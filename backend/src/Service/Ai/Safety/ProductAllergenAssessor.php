<?php

declare(strict_types=1);

namespace App\Service\Ai\Safety;

use App\Entity\HouseholdProductVariant;
use App\Enum\Allergen;
use App\Enum\ProductSource;
use App\Enum\SafetyVerdict;

/**
 * Confronte un allergène déclaré à ce qu'on sait vraiment de la composition d'un produit.
 *
 * Distinct de `DietaryGuard` : celui-ci grep le texte d'une recette générée, celui-ci
 * interroge la fiche structurée d'un produit (allergènes et traces Open Food Facts,
 * ou la correction qu'un foyer a déclarée pour un produit saisi à la main). Les deux
 * se complètent, ils ne se remplacent pas.
 */
final class ProductAllergenAssessor
{
    /**
     * @param Allergen $allergen dont `offTags()` n'est pas vide — Open Food Facts
     *                           ne modélise pas `Meat`/`Pork`/`Alcohol` comme des
     *                           allergènes, c'est à l'appelant de ne pas les
     *                           soumettre à ce contrôle structuré
     */
    public function assess(HouseholdProductVariant $variant, Allergen $allergen): SafetyVerdict
    {
        // La correction du foyer prime toujours sur la fiche catalogue : c'est
        // elle qui existe pour un produit qu'Open Food Facts ne connaît pas.
        $override = $variant->getAllergensOverride();
        if (null !== $override) {
            return $this->matches($allergen, $override, $variant->getProduct()?->getTracesTags() ?? []);
        }

        $product = $variant->getProduct();

        // Une fiche saisie à la main n'a jamais été synchronisée avec Open Food
        // Facts : un tableau vide y signifierait « jamais rempli », pas « vérifié
        // sans allergène ». Seule une correction explicite du foyer, ci-dessus,
        // peut alors lever le doute.
        if (null === $product || ProductSource::Manual === $product->getSource()) {
            return SafetyVerdict::Unknown;
        }

        return $this->matches($allergen, $product->getAllergensTags(), $product->getTracesTags());
    }

    /** @param list<string> $tags @param list<string> $traceTags */
    private function matches(Allergen $allergen, array $tags, array $traceTags): SafetyVerdict
    {
        $offTags = $allergen->offTags();

        return [] !== array_intersect($offTags, $tags) || [] !== array_intersect($offTags, $traceTags)
            ? SafetyVerdict::Incompatible
            : SafetyVerdict::Compatible;
    }
}
