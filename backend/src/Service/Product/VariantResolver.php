<?php

declare(strict_types=1);

namespace App\Service\Product;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\Product;
use App\Enum\DataOrigin;
use App\Enum\FoodCut;
use App\Enum\FoodNature;
use App\Enum\ProductTrait;
use App\Enum\Unit;
use App\Enum\UnitFamily;
use App\Exception\StockException;
use App\Repository\HouseholdProductVariantRepository;
use App\Service\Food\FoodCutDetector;
use App\Service\Food\FoodNatureDetector;
use App\Service\IngredientKeyGenerator;
use App\Service\ProductTraitDetector;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Trouve ou crée la variante d'un foyer — le point de passage unique vers
 * l'identité culinaire locale d'un aliment.
 *
 * Deux règles y sont tenues, et elles vont dans le même sens :
 *
 * - **on ne fusionne jamais deux choses différentes.** Une clé identique dans une
 *   autre famille d'unité, ou avec un autre état, donne une autre variante.
 *   L'inventaire peut compter deux lignes « tomates » ; il ne peut plus additionner
 *   des conserves à des fraîches ni perdre en silence des lots incompatibles ;
 * - **on ne scinde jamais deux choses identiques.** L'identité est normalisée puis
 *   hachée, et la base porte la contrainte : deux saisies équivalentes retombent
 *   sur la même variante, quel que soit l'ordre des traits.
 */
class VariantResolver
{
    public function __construct(
        private readonly HouseholdProductVariantRepository $variants,
        private readonly IngredientKeyGenerator $keyGenerator,
        private readonly ProductTraitDetector $traitDetector,
        private readonly FoodNatureDetector $natureDetector,
        private readonly FoodCutDetector $cutDetector,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<ProductTrait>|null $explicitTraits état choisi à la saisie ;
     *                                                `null` = déduire du libellé
     * @param FoodNature|null         $explicitNature nature corrigée à la saisie ;
     *                                                `null` = déduire du libellé
     * @param FoodCut|null            $explicitCut    découpe choisie à la saisie ;
     *                                                `null` = déduire du libellé
     */
    public function resolve(
        Household $household,
        ?Product $product,
        string $name,
        Unit $unit,
        ?array $explicitTraits = null,
        ?string $brand = null,
        ?string $category = null,
        ?FoodNature $explicitNature = null,
        ?FoodCut $explicitCut = null,
    ): HouseholdProductVariant {
        $label = trim($name);
        if ('' === $label) {
            throw new StockException('Un aliment ne peut pas être enregistré sans nom.');
        }

        [$traits, $traitsOrigin] = $this->resolveTraits($product, $label, $category, $explicitTraits);
        $effectiveCategory = $category ?? $product?->getCategory();

        // Le catalogue partagé ne porte pas de découpe : elle ne se lit que sur le
        // libellé du foyer, ou se choisit à la main. Deux niveaux, pas trois.
        $cut = $explicitCut ?? $this->cutDetector->detect($label, $effectiveCategory);

        $identity = VariantIdentity::of(
            $this->keyGenerator->generate($label),
            $unit->family(),
            $traits,
            $cut,
        );

        $existing = $this->variants->findOneByIdentity($household, $identity->hash());
        if (null !== $existing) {
            $this->completeMissingData($existing, $product, $brand, $category);

            if (null !== $explicitNature) {
                $this->changeNature($existing, $explicitNature);
            }

            // L'identité ayant déjà été retrouvée avec cette découpe, il n'y a rien
            // à réécrire — seulement à retenir que quelqu'un l'a confirmée.
            if (null !== $explicitCut && DataOrigin::User !== $existing->getCutOrigin()) {
                $existing->setCutOrigin(DataOrigin::User);
                $this->entityManager->flush();
            }

            return $existing;
        }

        return $this->create(
            $household,
            $product,
            $label,
            $identity,
            null !== $explicitTraits ? DataOrigin::User : $traitsOrigin,
            null !== $explicitCut ? DataOrigin::User : DataOrigin::Detected,
            $brand ?? $product?->getBrand(),
            $effectiveCategory,
            $explicitNature ?? $this->natureDetector->detect(
                $label,
                $effectiveCategory,
                $traits,
                scanned: null !== $product?->getBarcode(),
            ),
            null !== $explicitNature ? DataOrigin::User : DataOrigin::Detected,
        );
    }

    /**
     * Corrige la découpe — « ce n'est pas une escalope, c'est de l'émincé ».
     *
     * Contrairement à la nature, la découpe FAIT partie de l'identité : la corriger
     * réécrit l'empreinte et la clé lue par le générateur de recettes, et peut donc
     * heurter une variante existante. {@see reidentify()} refuse alors, plutôt que
     * de fusionner deux stocks que personne n'a demandé de réunir.
     */
    public function changeCut(HouseholdProductVariant $variant, ?FoodCut $cut): HouseholdProductVariant
    {
        $identity = $variant->identity()->withCut($cut);
        $variant->setCutOrigin(DataOrigin::User);

        if ($identity->hash() === $variant->getIdentityHash()) {
            // Même découpe : seule sa confirmation à la main est nouvelle, et elle
            // suffit à la mettre à l'abri des redéductions.
            $this->entityManager->flush();

            return $variant;
        }

        return $this->reidentify($variant, $identity);
    }

    /**
     * Corrige la nature d'un aliment — « ce n'est pas un produit conditionné ».
     *
     * Aucune ré-identification : la nature ne fait pas partie de l'identité de la
     * variante (voir {@see VariantIdentity}), la corriger ne peut donc jamais
     * heurter une autre ligne d'inventaire ni scinder un stock.
     */
    public function changeNature(HouseholdProductVariant $variant, FoodNature $nature): HouseholdProductVariant
    {
        if ($nature === $variant->getNature() && DataOrigin::User === $variant->getNatureOrigin()) {
            return $variant;
        }

        $variant->setNature($nature, DataOrigin::User);
        $this->entityManager->flush();

        return $variant;
    }

    /**
     * Applique une découpe DÉDUITE, sans la faire passer pour un choix humain.
     *
     * Réservé à la reprise du parc existant ({@see \App\Command\DetectFoodCutCommand}) :
     * la découpe change, son origine reste `Detected`, et une correction déjà
     * faite à la main n'est jamais écrasée — la règle tenue partout par
     * {@see DataOrigin}.
     */
    public function applyDetectedCut(HouseholdProductVariant $variant, ?FoodCut $cut): HouseholdProductVariant
    {
        if (DataOrigin::User === $variant->getCutOrigin()) {
            return $variant;
        }

        return $this->reidentify($variant, $variant->identity()->withCut($cut));
    }

    /**
     * La variante jumelle d'une autre famille d'unité.
     *
     * Réparer « g » choisi au lieu de « unité » ne change pas l'aliment mais change
     * son identité : le lot doit rejoindre la variante correspondante, sinon
     * l'inventaire additionnerait des grammes à des pièces.
     */
    public function forUnitFamily(HouseholdProductVariant $variant, UnitFamily $family): HouseholdProductVariant
    {
        if ($variant->getUnitFamily() === $family) {
            return $variant;
        }

        $identity = VariantIdentity::of(
            $variant->getIngredientKey(),
            $family,
            $variant->getTraits(),
            $variant->getCut(),
        );

        return $this->variants->findOneByIdentity($variant->getHousehold(), $identity->hash())
            ?? $this->create(
                $variant->getHousehold(),
                $variant->getProduct(),
                $variant->getName(),
                $identity,
                $variant->getTraitsOrigin(),
                $variant->getCutOrigin(),
                $variant->getBrand(),
                $variant->getCategory(),
                // Compter une courgette en grammes plutôt qu'en pièces ne la rend
                // pas conditionnée : la nature suit le lot dans sa nouvelle famille.
                $variant->getNature(),
                $variant->getNatureOrigin(),
                $variant->getNameOrigin(),
            );
    }

    /**
     * Renomme la variante DANS CE FOYER et régénère sa clé culinaire.
     *
     * Les deux ne se dissocient pas : un nom corrigé sans clé régénérée
     * rapprocherait l'aliment des mauvais lots à la prochaine recette.
     */
    public function rename(HouseholdProductVariant $variant, string $name): HouseholdProductVariant
    {
        $label = trim($name);
        if ('' === $label) {
            throw new StockException('Le nom d\'un aliment ne peut pas être vide.');
        }

        if ($label === $variant->getName()) {
            return $variant;
        }

        $identity = VariantIdentity::of(
            $this->keyGenerator->generate($label),
            $variant->getUnitFamily(),
            $variant->getTraits(),
            // Le nouveau nom peut nommer la forme — « Blanc de poulet » corrigé en
            // « Blanc de poulet en tranches ». Une découpe choisie à la main, elle,
            // ne se fait pas écraser par une relecture du libellé.
            DataOrigin::User === $variant->getCutOrigin()
                ? $variant->getCut()
                : $this->cutDetector->detect($label, $variant->getCategory()),
        );

        $variant->setName($label);
        $variant->setNameOrigin(DataOrigin::User);
        $this->refreshDetectedNature($variant, $variant->getTraits());

        return $this->reidentify($variant, $identity);
    }

    /** @param list<ProductTrait> $traits */
    public function changeTraits(HouseholdProductVariant $variant, array $traits): HouseholdProductVariant
    {
        $identity = VariantIdentity::of(
            $variant->getIngredientKey(),
            $variant->getUnitFamily(),
            $traits,
            $variant->getCut(),
        );

        if ($identity->hash() === $variant->getIdentityHash()) {
            return $variant;
        }

        $variant->setTraitsOrigin(DataOrigin::User);
        // Cocher « surgelé » range l'aliment du côté conditionné : laisser la
        // nature en arrière ferait cohabiter un légume « frais » avec un état qui
        // dit le contraire, et le formulaire de saisie mentirait au geste suivant.
        $this->refreshDetectedNature($variant, $identity->traits);

        return $this->reidentify($variant, $identity);
    }

    /**
     * Réévalue la nature après un changement de nom ou d'état — sauf si quelqu'un
     * l'a déjà tranchée : une correction humaine ne se fait pas écraser par une
     * déduction, c'est la règle tenue partout ailleurs par {@see DataOrigin}.
     *
     * Les traits sont passés plutôt que relus sur la variante : ceux qui comptent
     * ici sont les nouveaux, et ils ne lui sont appliqués qu'après le contrôle de
     * collision de {@see reidentify()} — qui peut encore tout refuser.
     *
     * @param list<ProductTrait> $traits
     */
    private function refreshDetectedNature(HouseholdProductVariant $variant, array $traits): void
    {
        if (DataOrigin::User === $variant->getNatureOrigin()) {
            return;
        }

        $variant->setNature(
            $this->natureDetector->detect(
                $variant->getName(),
                $variant->getCategory(),
                $traits,
                scanned: null !== $variant->getProduct()?->getBarcode(),
            ),
            DataOrigin::Detected,
        );
    }

    /**
     * Applique une nouvelle identité, ou refuse quand elle en heurte une existante.
     *
     * Fusionner automatiquement paraîtrait serviable et serait exactement le défaut
     * qu'on corrige : deux stocks distincts confondus sans que personne ne l'ait
     * demandé. On signale, l'utilisateur décide.
     */
    private function reidentify(
        HouseholdProductVariant $variant,
        VariantIdentity $identity,
    ): HouseholdProductVariant {
        $collision = $this->variants->findOneByIdentity($variant->getHousehold(), $identity->hash());

        if (null !== $collision && !$collision->getId()->equals($variant->getId())) {
            throw new StockException(sprintf(
                'Vous avez déjà « %s » dans votre inventaire. Consommez ou archivez ce lot, '
                .'ou déplacez-le explicitement plutôt que de fusionner deux stocks distincts.',
                $collision->getCulinaryLabel(),
            ));
        }

        $variant->applyIdentity($identity);
        $variant->setSlug($this->uniqueSlug($variant->getHousehold(), $identity, $variant->getSlug()));
        $this->entityManager->flush();

        return $variant;
    }

    /**
     * @param list<ProductTrait>|null $explicit
     *
     * @return array{list<ProductTrait>, DataOrigin}
     */
    private function resolveTraits(
        ?Product $product,
        string $name,
        ?string $category,
        ?array $explicit,
    ): array {
        // Ce que quelqu'un a coché l'emporte sur ce qu'une machine a déduit.
        if (null !== $explicit) {
            return [$explicit, DataOrigin::User];
        }

        if (null !== $product && [] !== $product->getTraits()) {
            return [$product->getTraits(), DataOrigin::Catalog];
        }

        return [
            $this->traitDetector->detect($name, $category ?? $product?->getCategory()),
            DataOrigin::Detected,
        ];
    }

    private function create(
        Household $household,
        ?Product $product,
        string $name,
        VariantIdentity $identity,
        DataOrigin $traitsOrigin,
        DataOrigin $cutOrigin,
        ?string $brand,
        ?string $category,
        FoodNature $nature,
        DataOrigin $natureOrigin,
        DataOrigin $nameOrigin = DataOrigin::Catalog,
    ): HouseholdProductVariant {
        $variant = new HouseholdProductVariant(
            $household,
            $name,
            $identity,
            $this->uniqueSlug($household, $identity),
            null !== $product ? $nameOrigin : DataOrigin::User,
            $traitsOrigin,
        );
        $variant->setCutOrigin($cutOrigin);
        $variant->setProduct($product);
        $variant->setBrand($brand);
        $variant->setCategory($category);
        $variant->setNature($nature, $natureOrigin);

        $this->entityManager->persist($variant);
        $this->entityManager->flush();

        return $variant;
    }

    /**
     * Un identifiant lisible et libre : `tomate`, puis `tomate-conserve`,
     * `blanc-de-poulet-escalope`, puis `tomate-piece`, et seulement en dernier
     * ressort `tomate-2`.
     *
     * Cet identifiant est recopié tel quel par le générateur de recettes : il doit
     * dire ce qui distingue la variante. `tomate-piece` s'explique, `tomate-2` non.
     * Le suffixe numérique ne survient donc que si deux variantes diffèrent par un
     * trait qui ne nomme pas l'aliment — épluché, sucré. Depuis que la découpe
     * entre dans le slug, le cas qui le déclenchait le plus souvent a disparu.
     */
    private function uniqueSlug(Household $household, VariantIdentity $identity, ?string $keep = null): string
    {
        $base = $identity->slugBase();
        $taken = array_flip($this->variants->takenSlugs($household, $base));

        if (null !== $keep) {
            unset($taken[$keep]);
        }

        if (!isset($taken[$base])) {
            return $base;
        }

        // Deux stocks du même aliment comptés différemment : le dire.
        $byFamily = sprintf('%s-%s', $base, $identity->unitFamily->slug());
        if (!isset($taken[$byFamily])) {
            return $byFamily;
        }

        for ($suffix = 2; $suffix < 1000; ++$suffix) {
            $candidate = sprintf('%s-%d', $base, $suffix);

            if (!isset($taken[$candidate])) {
                return $candidate;
            }
        }

        return sprintf('%s-%s', $base, substr($identity->hash(), 0, 8));
    }

    /**
     * Une variante née d'une saisie libre peut être rattrapée par un scan :
     * on complète alors ce qui manquait, sans jamais écraser une correction locale.
     */
    private function completeMissingData(
        HouseholdProductVariant $variant,
        ?Product $product,
        ?string $brand,
        ?string $category,
    ): void {
        $changed = false;

        if (null !== $product && null === $variant->getProduct()) {
            $variant->setProduct($product);
            $changed = true;
        }

        if (null === $variant->getBrand() && null !== ($brand ?? $product?->getBrand())) {
            $variant->setBrand($brand ?? $product?->getBrand());
            $changed = true;
        }

        if (null === $variant->getCategory() && null !== ($category ?? $product?->getCategory())) {
            $variant->setCategory($category ?? $product?->getCategory());
            $changed = true;
        }

        if ($changed) {
            $this->entityManager->flush();
        }
    }
}
