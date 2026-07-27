<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Enum\Allergen;
use App\Enum\RecipePlanStatus;
use App\Enum\SafetyVerdict;
use App\Enum\Unit;
use App\Repository\HouseholdProductVariantRepository;
use App\Service\Ai\Safety\DietaryGuard;
use App\Service\Ai\Safety\DietaryViolation;
use App\Service\Ai\Safety\ProductAllergenAssessor;
use App\Service\Ai\Safety\RecipeSafetyFilter;
use App\Service\Stock\FefoAllocator;
use App\Service\TextNormalizer;

/**
 * Le plan de préparation ACTUEL d'une recette — recalculé, jamais rejoué.
 *
 * Une recette générée n'est vérifiée qu'une fois, au moment de sa génération.
 * Une recette enregistrée est resservie plus tard, telle quelle, sans que rien
 * ne revérifie si le stock, les exclusions ou les aliments qu'elle nomme ont
 * changé depuis. Ce service comble ce trou : il rejoue le même contrôle
 * lexical qu'à la génération (`RecipeSafetyFilter`), y ajoute un contrôle
 * structuré sur la composition réelle des produits pour les allergies
 * strictes (`ProductAllergenAssessor`), détecte les aliments renommés ou
 * supprimés depuis, et vérifie le stock disponible — le tout en lecture seule.
 *
 * Un seul point d'entrée, appelé à la génération, à l'ouverture d'une recette
 * enregistrée et juste avant sa confirmation : les trois moments voient
 * exactement le même verdict.
 */
final class RecipePlanService
{
    public function __construct(
        private readonly RecipeSafetyFilter $safetyFilter,
        private readonly HouseholdProductVariantRepository $variants,
        private readonly ProductAllergenAssessor $assessor,
        private readonly FefoAllocator $allocator,
        private readonly TextNormalizer $normalizer,
    ) {
    }

    /**
     * @param list<string>          $strictLabels exclusions à sévérité allergie à
     *                                             confronter à la composition des produits —
     *                                             foyer et, le cas échéant, demande en cours
     * @param array<string, mixed>  $recipe       document déjà normalisé (`RecipeNormalizer`)
     */
    public function plan(DietaryGuard $guard, array $strictLabels, Household $household, array $recipe): RecipePlan
    {
        $lines = [];
        $resolved = [];
        $labels = [];

        foreach ($this->collectEntries($recipe) as $key => $fallbackLabel) {
            $variant = $this->variants->findOneBySlug($household, $key);

            if (null === $variant) {
                $lines[] = new RecipePlanLine($key, $fallbackLabel, RecipePlanStatus::NeedsReassociation, sprintf(
                    '« %s » ne correspond plus à aucun aliment de votre inventaire actuel.',
                    $fallbackLabel,
                ));
                continue;
            }

            $resolved[$key] = $variant;
            $labels[$key] = $variant->getCulinaryLabel();
        }

        // Contrôle lexical : le même que celui appliqué à la génération, rejoué
        // sur le texte de la recette telle qu'elle est affichée aujourd'hui.
        $lexicalViolation = $guard->inspectFields($this->safetyFilter->inspectableFields($recipe, $labels));

        // Contrôle structuré : seulement pour les allergies strictes qu'Open Food
        // Facts modélise. Une préférence ou un régime reste couvert par le seul
        // contrôle lexical, comme avant.
        $strictAllergens = $this->strictAllergens($strictLabels);

        foreach ($resolved as $key => $variant) {
            $line = $this->assessVariant($key, $labels[$key], $variant, $strictAllergens);
            if (null !== $line) {
                $lines[] = $line;
            }
        }

        // Stock : en lecture seule, sans verrou — un dry-run qui ne fige rien.
        foreach ($this->consumptionEntries($recipe) as $key => $consumption) {
            if (!isset($resolved[$key])) {
                continue; // déjà signalé en réassociation ci-dessus.
            }

            $stockPlan = $this->allocator->plan($resolved[$key], $consumption['quantity'], $consumption['unit']);

            if (!$stockPlan->isComplete()) {
                $lines[] = new RecipePlanLine($key, $labels[$key], RecipePlanStatus::NeedsShopping, sprintf(
                    '« %s » : %s %s demandé(s), %s disponible(s) actuellement.',
                    $labels[$key],
                    $stockPlan->requested,
                    $stockPlan->unit->label(),
                    $stockPlan->allocated,
                ));
            }
        }

        return $this->summarize($lines, $lexicalViolation, $strictAllergens);
    }

    /** @param list<Allergen> $strictAllergens */
    private function assessVariant(
        string $key,
        string $label,
        HouseholdProductVariant $variant,
        array $strictAllergens,
    ): ?RecipePlanLine {
        foreach ($strictAllergens as $allergen) {
            $verdict = $this->assessor->assess($variant, $allergen);

            if (SafetyVerdict::Incompatible === $verdict) {
                return new RecipePlanLine($key, $label, RecipePlanStatus::Incompatible, sprintf(
                    '« %s » contient %s, exclu par votre foyer.',
                    $label,
                    $allergen->label(),
                ));
            }

            if (SafetyVerdict::Unknown === $verdict) {
                return new RecipePlanLine($key, $label, RecipePlanStatus::SafetyUnknown, sprintf(
                    '« %s » n\'a pas de fiche allergènes vérifiée pour %s : par prudence, cette recette '
                    .'n\'est pas présentée comme sûre.',
                    $label,
                    $allergen->label(),
                ));
            }
        }

        return null;
    }

    /**
     * Chaque clé d'ingrédient une seule fois, qu'elle vienne de l'inventaire
     * utilisé ou de la proposition de consommation.
     *
     * @param array<string, mixed> $recipe
     *
     * @return array<string, string> clé => libellé de repli, avant résolution
     */
    private function collectEntries(array $recipe): array
    {
        $entries = [];

        foreach (['ingredientsFromInventory', 'stockConsumptionProposal'] as $field) {
            foreach (is_array($recipe[$field] ?? null) ? $recipe[$field] : [] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $key = is_string($entry['key'] ?? null) ? trim($entry['key']) : '';
                if ('' === $key || isset($entries[$key])) {
                    continue;
                }

                $entries[$key] = is_string($entry['label'] ?? null) ? $entry['label'] : $key;
            }
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $recipe
     *
     * @return array<string, array{quantity: float, unit: Unit}>
     */
    private function consumptionEntries(array $recipe): array
    {
        $entries = [];

        foreach (is_array($recipe['stockConsumptionProposal'] ?? null) ? $recipe['stockConsumptionProposal'] : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $key = is_string($entry['key'] ?? null) ? trim($entry['key']) : '';
            $unit = Unit::tryFrom((string) ($entry['unit'] ?? ''));
            $quantity = is_numeric($entry['quantity'] ?? null) ? (float) $entry['quantity'] : 0.0;

            if ('' === $key || null === $unit || $quantity <= 0) {
                continue;
            }

            $entries[$key] = ['quantity' => $quantity, 'unit' => $unit];
        }

        return $entries;
    }

    /**
     * @param list<string> $strictLabels
     *
     * @return list<Allergen>
     */
    private function strictAllergens(array $strictLabels): array
    {
        $byValue = [];

        foreach ($strictLabels as $label) {
            $allergen = Allergen::fromExclusionText($this->normalizer->toWords($label));

            if (null !== $allergen && [] !== $allergen->offTags()) {
                $byValue[$allergen->value] = $allergen;
            }
        }

        return array_values($byValue);
    }

    /**
     * @param list<RecipePlanLine> $lines
     * @param list<Allergen>       $strictAllergens
     */
    private function summarize(array $lines, ?DietaryViolation $lexicalViolation, array $strictAllergens): RecipePlan
    {
        $checkedExclusions = array_map(static fn (Allergen $allergen): string => $allergen->label(), $strictAllergens);

        if (null !== $lexicalViolation) {
            return new RecipePlan(
                RecipePlanStatus::Incompatible,
                sprintf('Cette recette ne respecte plus vos exclusions : %s.', $lexicalViolation->describe()),
                $lines,
                $checkedExclusions,
                new \DateTimeImmutable(),
            );
        }

        $status = RecipePlanStatus::Ready;
        $reason = null;

        foreach ($lines as $line) {
            if ($line->status->severity() < $status->severity()) {
                $status = $line->status;
                $reason = $line->detail;
            }
        }

        return new RecipePlan($status, $reason, $lines, $checkedExclusions, new \DateTimeImmutable());
    }
}
