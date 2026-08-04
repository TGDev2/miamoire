<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DataOrigin;
use App\Enum\FoodCut;
use App\Enum\FoodNature;
use App\Enum\ProductTrait;
use App\Enum\StorageLocation;
use App\Enum\UnitFamily;
use App\Repository\HouseholdProductVariantRepository;
use App\Service\Product\VariantIdentity;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * La représentation culinaire d'un aliment DANS UN FOYER.
 *
 * Le modèle distinguait « référence » (Product, partagé, cache Open Food Facts) et
 * « lot » (StockItem, ce qui est réellement chez soi). Il lui manquait le niveau
 * intermédiaire, et cette absence avait trois conséquences visibles :
 *
 * - corriger le nom ou l'état d'un aliment modifiait le `Product` global, donc ce
 *   que voyaient les autres foyers ;
 * - des tomates fraîches et des tomates en conserve, partageant la clé `tomate`,
 *   étaient présentées au générateur de recettes comme une quantité unique portant
 *   le trait `canned` ;
 * - un renommage ne régénérait pas la clé d'ingrédient, laissant une clé morte
 *   derrière lui.
 *
 * La variante corrige les trois : elle appartient à un foyer, porte tout ce qui est
 * corrigeable, et son identité — clé, famille d'unité, traits — est garantie unique
 * par la base. `Product` redevient ce qu'il n'aurait jamais dû cesser d'être :
 * un catalogue canonique, en lecture pour tout le monde.
 */
#[ORM\Entity(repositoryClass: HouseholdProductVariantRepository::class)]
#[ORM\Table(name: 'household_product_variant')]
#[ORM\UniqueConstraint(name: 'uniq_variant_identity', columns: ['household_id', 'identity_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_variant_slug', columns: ['household_id', 'slug'])]
#[ORM\Index(columns: ['household_id', 'ingredient_key'], name: 'idx_variant_ingredient_key')]
#[ORM\HasLifecycleCallbacks]
class HouseholdProductVariant
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Household::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Household $household;

    /**
     * Le produit canonique dont cette variante descend, quand il existe.
     *
     * Nullable : une saisie libre — « reste de gratin », « tomates du jardin » —
     * n'a aucune raison de créer une référence mondiale.
     */
    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Product $product = null;

    /** Le nom tel qu'il s'affiche DANS CE FOYER. Le renommer ne touche personne d'autre. */
    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $ingredientKey;

    /**
     * Identifiant lisible, unique dans le foyer : `tomate`, `tomate-conserve`.
     *
     * C'est cette valeur — et non un UUID — que le générateur de recettes recopie
     * dans sa proposition de consommation. Un modèle recopie fidèlement un mot ;
     * il abîme un identifiant de trente-six caractères.
     */
    #[ORM\Column(length: 255)]
    private string $slug;

    /** Empreinte de (clé, famille d'unité, traits) — l'unicité est portée par la base. */
    #[ORM\Column(length: 64)]
    private string $identityHash;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $brand = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $category = null;

    /** @var list<string> valeurs de ProductTrait */
    #[ORM\Column(type: 'json')]
    private array $traits = [];

    /**
     * La forme sous laquelle l'aliment se trouve : escalopes, émincé, en tranches.
     *
     * Fait partie de {@see VariantIdentity}, au contraire de {@see $nature} : deux
     * découpes ne sont pas deux états d'un même stock, ce sont deux ingrédients
     * — une escalope se saisit trois minutes là où un blanc entier en cuit vingt.
     *
     * `null` signifie « inconnue », jamais « entière » : c'est la valeur de tout
     * ce qui existait avant cet axe, et celle de tout aliment dont la forme ne
     * change rien à la cuisson.
     */
    #[ORM\Column(type: 'string', length: 16, enumType: FoodCut::class, nullable: true)]
    private ?FoodCut $cut = null;

    #[ORM\Column(type: 'string', length: 16, enumType: DataOrigin::class)]
    private DataOrigin $cutOrigin = DataOrigin::Detected;

    /**
     * La famille d'unité fait partie de l'identité.
     *
     * Trois pièces et quatre cents grammes du même aliment ne s'additionnent pas :
     * ce sont deux lignes d'inventaire, pas une quantité tronquée en silence.
     */
    #[ORM\Column(type: 'string', length: 16, enumType: UnitFamily::class)]
    private UnitFamily $unitFamily;

    #[ORM\Column(type: 'string', length: 16, enumType: DataOrigin::class)]
    private DataOrigin $nameOrigin;

    #[ORM\Column(type: 'string', length: 16, enumType: DataOrigin::class)]
    private DataOrigin $traitsOrigin;

    /**
     * Frais ou conditionné — ce qui décide du formulaire de saisie, de
     * l'estimation d'office de la date, et du rang dans les recettes.
     *
     * Volontairement HORS de {@see VariantIdentity} : les traits `canned` /
     * `frozen` / `dried` séparent déjà « tomate » de « tomate-conserve ». L'ajouter
     * au hachage scinderait les variantes existantes sans rien distinguer de plus.
     * C'est un attribut descriptif et corrigeable, comme la catégorie.
     */
    #[ORM\Column(type: 'string', length: 16, enumType: FoodNature::class)]
    private FoodNature $nature = FoodNature::Packaged;

    #[ORM\Column(type: 'string', length: 16, enumType: DataOrigin::class)]
    private DataOrigin $natureOrigin = DataOrigin::Detected;

    /**
     * Correction ou validation par CE foyer des allergènes de cette variante.
     *
     * `null` : rien n'a été déclaré, on se fie à `Product::getAllergensTags()` —
     * inconnu si le produit n'en a pas non plus (saisie manuelle jamais
     * synchronisée avec Open Food Facts, notamment). Un tableau vide, y compris
     * `[]`, est une déclaration à part entière : « vérifié, aucun des allergènes
     * suivis n'est présent ». Les deux ne doivent jamais être confondus, d'où
     * l'absence de valeur par défaut sur cette colonne.
     *
     * @var list<string>|null identifiants de taxonomie OFF, ex. `en:milk`
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $allergensOverride = null;

    /**
     * Contenance du dernier exemplaire acheté — la meilleure prédiction du
     * prochain achat, pas une moyenne à entretenir. Alimenté par
     * {@see \App\Service\Stock\StockService::add()}, jamais par un
     * réapprovisionnement d'un lot existant.
     */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $usualPackageSize = null;

    #[ORM\Column(type: 'string', length: 32, enumType: StorageLocation::class, nullable: true)]
    private ?StorageLocation $usualStorageLocation = null;

    /**
     * `null` : le conseiller avant les courses ({@see \App\Service\Shopping\ShoppingAdvisorSelector})
     * peut proposer cette variante. Non `null` : le foyer a demandé à ne plus la
     * voir suggérée — ses suggestions ne réapparaissent qu'après `unmuteShoppingAdvice()`.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $shoppingAdvisorMutedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /** @param list<ProductTrait> $traits */
    public function __construct(
        Household $household,
        string $name,
        VariantIdentity $identity,
        string $slug,
        DataOrigin $nameOrigin = DataOrigin::Detected,
        DataOrigin $traitsOrigin = DataOrigin::Detected,
    ) {
        $this->id = Uuid::v7();
        $this->household = $household;
        $this->name = $name;
        $this->slug = $slug;
        $this->nameOrigin = $nameOrigin;
        $this->traitsOrigin = $traitsOrigin;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;

        $this->applyIdentity($identity);
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** Les quatre champs d'identité changent toujours ensemble, jamais séparément. */
    public function applyIdentity(VariantIdentity $identity): void
    {
        $this->ingredientKey = $identity->ingredientKey;
        $this->unitFamily = $identity->unitFamily;
        $this->traits = ProductTrait::toValues($identity->traits);
        $this->cut = $identity->cut;
        $this->identityHash = $identity->hash();
    }

    public function identity(): VariantIdentity
    {
        return VariantIdentity::of($this->ingredientKey, $this->unitFamily, $this->getTraits(), $this->cut);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getHousehold(): Household
    {
        return $this->household;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): void
    {
        $this->product = $product;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getIngredientKey(): string
    {
        return $this->ingredientKey;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }

    public function getIdentityHash(): string
    {
        return $this->identityHash;
    }

    public function getBrand(): ?string
    {
        return $this->brand;
    }

    public function setBrand(?string $brand): void
    {
        $this->brand = $brand;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): void
    {
        $this->category = $category;
    }

    /** @return list<ProductTrait> */
    public function getTraits(): array
    {
        return ProductTrait::fromValues($this->traits);
    }

    public function getCut(): ?FoodCut
    {
        return $this->cut;
    }

    public function getCutOrigin(): DataOrigin
    {
        return $this->cutOrigin;
    }

    /**
     * La découpe elle-même passe par {@see applyIdentity()} — elle fait partie de
     * l'identité, et changer l'un sans l'autre laisserait une empreinte fausse.
     * Seule son origine se pose ici, comme pour les traits.
     */
    public function setCutOrigin(DataOrigin $origin): void
    {
        $this->cutOrigin = $origin;
    }

    public function getUnitFamily(): UnitFamily
    {
        return $this->unitFamily;
    }

    public function getNameOrigin(): DataOrigin
    {
        return $this->nameOrigin;
    }

    public function setNameOrigin(DataOrigin $origin): void
    {
        $this->nameOrigin = $origin;
    }

    public function getTraitsOrigin(): DataOrigin
    {
        return $this->traitsOrigin;
    }

    public function setTraitsOrigin(DataOrigin $origin): void
    {
        $this->traitsOrigin = $origin;
    }

    public function getNature(): FoodNature
    {
        return $this->nature;
    }

    public function isFresh(): bool
    {
        return $this->nature->isFresh();
    }

    public function getNatureOrigin(): DataOrigin
    {
        return $this->natureOrigin;
    }

    /**
     * La nature et son origine changent toujours ensemble : sans quoi une nature
     * corrigée à la main serait réécrite au prochain scan, et la correction
     * n'aurait servi qu'une fois.
     */
    public function setNature(FoodNature $nature, DataOrigin $origin): void
    {
        $this->nature = $nature;
        $this->natureOrigin = $origin;
    }

    /** @return list<string>|null */
    public function getAllergensOverride(): ?array
    {
        return $this->allergensOverride;
    }

    /** @param list<string>|null $allergensOverride */
    public function setAllergensOverride(?array $allergensOverride): void
    {
        $this->allergensOverride = null !== $allergensOverride ? array_values($allergensOverride) : null;
    }

    public function getUsualPackageSize(): ?float
    {
        return $this->usualPackageSize;
    }

    public function getUsualStorageLocation(): ?StorageLocation
    {
        return $this->usualStorageLocation;
    }

    /**
     * Retient un achat — contenance d'un exemplaire et emplacement — pour
     * préremplir la prochaine ligne d'un même produit au « Retour de
     * courses ». Écrase la valeur précédente : le dernier achat est la
     * meilleure prédiction du suivant.
     */
    public function rememberPurchase(float $packageSize, StorageLocation $location): void
    {
        $this->usualPackageSize = round($packageSize, 3);
        $this->usualStorageLocation = $location;
    }

    public function getShoppingAdvisorMutedAt(): ?\DateTimeImmutable
    {
        return $this->shoppingAdvisorMutedAt;
    }

    public function isShoppingAdviceMuted(): bool
    {
        return null !== $this->shoppingAdvisorMutedAt;
    }

    public function muteShoppingAdvice(\DateTimeImmutable $at): void
    {
        $this->shoppingAdvisorMutedAt = $at;
    }

    public function unmuteShoppingAdvice(): void
    {
        $this->shoppingAdvisorMutedAt = null;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** « Pâtes penne — Barilla ». */
    public function getDisplayName(): string
    {
        return null !== $this->brand && '' !== $this->brand
            ? sprintf('%s — %s', $this->name, $this->brand)
            : $this->name;
    }

    /**
     * Le libellé lu par le générateur de recettes : « Tomates (en conserve) »,
     * « Blanc de poulet (émincé) ».
     *
     * La marque en est volontairement absente — elle n'apprend rien sur la cuisine
     * — mais l'état, lui, change la recette.
     *
     * Un état que le nom énonce déjà n'est pas répété : « Blanc de poulet en
     * tranches » se suffit, « Blanc de poulet en tranches (en tranches) » ne dit
     * rien de plus et se lit mal, y compris par le modèle.
     */
    public function getCulinaryLabel(): string
    {
        $said = mb_strtolower($this->name);

        $states = array_values(array_filter(
            $this->identity()->stateLabels(),
            static fn (string $state): bool => !self::isAlreadySaid($state, $said),
        ));

        return [] === $states ? $this->name : sprintf('%s (%s)', $this->name, implode(', ', $states));
    }

    /**
     * Un état est déjà dit quand le nom porte l'un de ses mots pleins.
     *
     * Le mot, pas la locution : « Oignons émincés » contient « émincé », « Côte de
     * porc » contient « côte » de « pavé / côte ». Les mots courts — « en », « de »,
     * « à » — sont écartés, sinon tout nom français les contiendrait.
     */
    private static function isAlreadySaid(string $state, string $lowercaseName): bool
    {
        foreach (preg_split('/[^\p{L}]+/u', $state, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (mb_strlen($word) > 3 && str_contains($lowercaseName, $word)) {
                return true;
            }
        }

        return false;
    }
}
