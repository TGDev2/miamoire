<?php

declare(strict_types=1);

namespace App\Service\Product;

use App\Enum\FoodCut;
use App\Enum\ProductTrait;
use App\Enum\UnitFamily;

/**
 * L'identité culinaire d'un aliment dans un foyer.
 *
 * Regrouper sur la seule clé d'ingrédient confondait des tomates fraîches et des
 * tomates en conserve : une quantité unique, portant l'union de leurs états, était
 * présentée au générateur de recettes. Pire, les lots exprimés dans une autre
 * famille d'unité disparaissaient silencieusement de l'agrégat.
 *
 * Quatre choses définissent donc l'identité, et elles sont toutes les quatre
 * nécessaires :
 *
 * - la clé d'ingrédient — de quoi il s'agit ;
 * - la famille d'unité — comment il se compte, car aucune conversion n'existe
 *   entre 3 pièces et 400 g ;
 * - les traits — car une conserve est déjà cuite et pelée ;
 * - la découpe — car une escalope se saisit trois minutes quand un blanc entier
 *   en cuit vingt. C'est le dernier axe arrivé, et celui qui manquait le jour où
 *   une recette d'escalopes a été écrite sur du blanc de poulet en tranches.
 *
 * Deux valeurs en sortent : un `hash` que la base rend unique, et un `slug`
 * lisible que le modèle de recettes recopie sans se tromper.
 */
final readonly class VariantIdentity
{
    /** @param list<ProductTrait> $traits triés et dédoublonnés */
    private function __construct(
        public string $ingredientKey,
        public UnitFamily $unitFamily,
        public array $traits,
        public ?FoodCut $cut,
    ) {
    }

    /** @param list<ProductTrait> $traits */
    public static function of(
        string $ingredientKey,
        UnitFamily $unitFamily,
        array $traits,
        ?FoodCut $cut = null,
    ): self {
        $key = trim($ingredientKey);

        return new self(
            '' === $key ? 'inconnu' : $key,
            $unitFamily,
            self::normalizeTraits($traits),
            $cut,
        );
    }

    /** La même identité, sous une autre découpe — le reste est inchangé. */
    public function withCut(?FoodCut $cut): self
    {
        return new self($this->ingredientKey, $this->unitFamily, $this->traits, $cut);
    }

    /**
     * Empreinte stable : c'est elle que porte l'index unique `(foyer, identité)`.
     *
     * L'ordre des traits ne doit jamais changer le résultat — sans quoi deux
     * saisies identiques créeraient deux variantes, et l'inventaire se scinderait
     * sans raison visible.
     *
     * La découpe n'entre dans l'empreinte que lorsqu'elle est connue. Ce n'est
     * pas une commodité : c'est ce qui garantit qu'une variante enregistrée avant
     * l'arrivée de cet axe garde EXACTEMENT l'empreinte qu'elle avait. Aucune
     * variante ne se scinde, aucune migration de hachage n'est nécessaire, et
     * l'inventaire de la veille est celui du lendemain.
     */
    public function hash(): string
    {
        $parts = [
            $this->ingredientKey,
            $this->unitFamily->value,
            implode(',', ProductTrait::toValues($this->traits)),
        ];

        if (null !== $this->cut) {
            $parts[] = $this->cut->value;
        }

        return hash('sha256', implode('|', $parts));
    }

    /**
     * Base de l'identifiant lisible envoyé au générateur de recettes :
     * `tomate`, `tomate-conserve`, `blanc-de-poulet-escalope`.
     *
     * Seul le trait de forme le plus déterminant est retenu. Une conserve étant
     * toujours précuite, faire figurer les deux n'apprendrait rien et rendrait la
     * clé illisible. La découpe s'ajoute ensuite, car elle se cumule avec l'état :
     * du poulet surgelé émincé est bien `poulet-surgele-emince`.
     */
    public function slugBase(): string
    {
        // « Oignons émincés » donne déjà la clé `oignon-emince` : y recoller le
        // fragment de découpe produirait `oignon-emince-emince`. Un identifiant
        // que le modèle recopie doit se lire, et un mot répété ne distingue rien.
        $parts = [$this->ingredientKey];

        foreach ([$this->primaryForm()?->slug(), $this->cut?->slug()] as $fragment) {
            if (null !== $fragment && !$this->keyAlreadySays($fragment)) {
                $parts[] = $fragment;
            }
        }

        return implode('-', $parts);
    }

    /**
     * La clé porte-t-elle déjà ce fragment ?
     *
     * Comparaison par préfixe et non par égalité : « Blanc de poulet en tranches »
     * donne la clé `blanc-poulet-tranche`, au singulier, quand le fragment de
     * découpe est `tranches`. Deux mots pour la même chose — les recoller ferait
     * `blanc-poulet-tranche-tranches`.
     */
    private function keyAlreadySays(string $fragment): bool
    {
        foreach (explode('-', $this->ingredientKey) as $token) {
            if (mb_strlen($token) > 3 && str_starts_with($fragment, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Les libellés humains de l'état, un par axe : « en conserve », « émincé ».
     *
     * Rendus séparément et non déjà assemblés, parce que l'appelant en écarte
     * certains : un aliment nommé « Blanc de poulet en tranches » n'a pas à
     * s'afficher « Blanc de poulet en tranches (en tranches) ».
     * Voir {@see \App\Entity\HouseholdProductVariant::getCulinaryLabel()}.
     *
     * @return list<string>
     */
    public function stateLabels(): array
    {
        $parts = [];

        if (null !== ($form = $this->primaryForm())) {
            $parts[] = mb_strtolower($form->label());
        }

        if (null !== $this->cut) {
            $parts[] = mb_strtolower($this->cut->label());
        }

        return $parts;
    }

    /**
     * Le trait de forme le plus déterminant, dans l'ordre de déclaration de l'enum :
     * `canned` prime sur `precooked`, qu'il implique de toute façon.
     */
    private function primaryForm(): ?ProductTrait
    {
        foreach ($this->traits as $trait) {
            if ($trait->isForm()) {
                return $trait;
            }
        }

        return null;
    }

    /**
     * @param list<ProductTrait> $traits
     *
     * @return list<ProductTrait>
     */
    private static function normalizeTraits(array $traits): array
    {
        $order = ProductTrait::cases();

        return array_values(array_filter(
            $order,
            static fn (ProductTrait $candidate) => in_array($candidate, $traits, true),
        ));
    }
}
