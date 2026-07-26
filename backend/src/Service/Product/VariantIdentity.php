<?php

declare(strict_types=1);

namespace App\Service\Product;

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
 * Trois choses définissent donc l'identité, et elles sont toutes les trois
 * nécessaires :
 *
 * - la clé d'ingrédient — de quoi il s'agit ;
 * - la famille d'unité — comment il se compte, car aucune conversion n'existe
 *   entre 3 pièces et 400 g ;
 * - les traits — car une conserve est déjà cuite et pelée.
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
    ) {
    }

    /** @param list<ProductTrait> $traits */
    public static function of(string $ingredientKey, UnitFamily $unitFamily, array $traits): self
    {
        $key = trim($ingredientKey);

        return new self(
            '' === $key ? 'inconnu' : $key,
            $unitFamily,
            self::normalizeTraits($traits),
        );
    }

    /**
     * Empreinte stable : c'est elle que porte l'index unique `(foyer, identité)`.
     *
     * L'ordre des traits ne doit jamais changer le résultat — sans quoi deux
     * saisies identiques créeraient deux variantes, et l'inventaire se scinderait
     * sans raison visible.
     */
    public function hash(): string
    {
        return hash('sha256', implode('|', [
            $this->ingredientKey,
            $this->unitFamily->value,
            implode(',', ProductTrait::toValues($this->traits)),
        ]));
    }

    /**
     * Base de l'identifiant lisible envoyé au générateur de recettes :
     * `tomate`, `tomate-conserve`, `tomate-surgele`.
     *
     * Seul le trait de forme le plus déterminant est retenu. Une conserve étant
     * toujours précuite, faire figurer les deux n'apprendrait rien et rendrait la
     * clé illisible.
     */
    public function slugBase(): string
    {
        $form = $this->primaryForm();

        return null === $form
            ? $this->ingredientKey
            : sprintf('%s-%s', $this->ingredientKey, $form->slug());
    }

    /** Libellé humain d'un état : « en conserve », « surgelé ». */
    public function stateLabel(): ?string
    {
        $form = $this->primaryForm();

        return null === $form ? null : mb_strtolower($form->label());
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
