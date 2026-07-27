<?php

declare(strict_types=1);

namespace App\Service\Ai\Safety;

/**
 * Écarte les recettes qui ne respectent pas les exclusions du foyer.
 *
 * Le modèle reçoit déjà la consigne ; ce filtre ne lui fait pas confiance. Une
 * recette qui touche un aliment exclu est **retirée**, pas annotée : signaler
 * « attention, contient des arachides » sur une proposition qu'on affiche quand
 * même laisse la responsabilité à celui qui lit. Une allergie ne se délègue pas.
 *
 * Tout ce qui nomme un aliment est inspecté — pas seulement la liste des
 * ingrédients. Une recette peut être conforme dans ses ingrédients et
 * introduire l'allergène à l'étape 4 (« servir avec du pain grillé »), ou par
 * une substitution proposée en passant.
 */
class RecipeSafetyFilter
{
    /**
     * @param list<array<string, mixed>> $recipes
     * @param array<string, string>      $inventoryLabels clé de variante → libellé culinaire
     *
     * @return array{recipes: list<array<string, mixed>>, rejected: list<array{recipeName: string, reason: string}>}
     */
    public function apply(array $recipes, DietaryGuard $guard, array $inventoryLabels = []): array
    {
        if ($guard->isEmpty()) {
            return ['recipes' => array_values($recipes), 'rejected' => []];
        }

        $kept = [];
        $rejected = [];

        foreach ($recipes as $recipe) {
            $violation = $guard->inspectFields($this->inspectableFields($recipe, $inventoryLabels));

            if (null === $violation) {
                $kept[] = $recipe;
                continue;
            }

            $rejected[] = [
                'recipeName' => is_string($recipe['recipeName'] ?? null) ? $recipe['recipeName'] : 'Proposition sans nom',
                'reason' => $violation->describe(),
            ];
        }

        return ['recipes' => $kept, 'rejected' => $rejected];
    }

    /**
     * Les champs d'une recette où un aliment exclu peut se cacher.
     *
     * Rendue publique pour `RecipePlanService`, qui rejoue exactement le même
     * contrôle lexical au moment d'ouvrir ou de confirmer une recette — la
     * première ligne de défense ne doit pas diverger entre génération et
     * réévaluation.
     *
     * @param array<string, mixed>  $recipe
     * @param array<string, string> $inventoryLabels
     *
     * @return array<string, string|list<string>>
     */
    public function inspectableFields(array $recipe, array $inventoryLabels): array
    {
        return [
            'le titre' => is_string($recipe['recipeName'] ?? null) ? $recipe['recipeName'] : '',
            'le résumé' => is_string($recipe['summary'] ?? null) ? $recipe['summary'] : '',
            'les ingrédients' => $this->labels($recipe['ingredientsFromInventory'] ?? null),
            'les ingrédients à acheter' => $this->labels($recipe['missingIngredients'] ?? null),
            'les adaptations' => $this->substitutions($recipe['substitutions'] ?? null),
            'l\'étape' => $this->steps($recipe['steps'] ?? null),
            'la déduction de stock' => $this->proposal($recipe['stockConsumptionProposal'] ?? null, $inventoryLabels),
        ];
    }

    /** @return list<string> */
    private function labels(mixed $entries): array
    {
        if (!is_array($entries)) {
            return [];
        }

        $labels = [];
        foreach ($entries as $entry) {
            if (is_array($entry) && is_string($entry['label'] ?? null)) {
                $labels[] = $entry['label'];
            }
        }

        return $labels;
    }

    /** @return list<string> */
    private function substitutions(mixed $entries): array
    {
        if (!is_array($entries)) {
            return [];
        }

        $texts = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            foreach (['original', 'replacedBy', 'reason'] as $field) {
                if (is_string($entry[$field] ?? null)) {
                    $texts[] = $entry[$field];
                }
            }
        }

        return $texts;
    }

    /** @return list<string> */
    private function steps(mixed $steps): array
    {
        return is_array($steps)
            ? array_values(array_filter($steps, 'is_string'))
            : [];
    }

    /**
     * La proposition de consommation se juge sur le libellé réel de l'inventaire,
     * pas sur celui que le modèle a recopié : c'est ce libellé-là qui décrit
     * l'aliment qui serait retiré du stock.
     *
     * @param array<string, string> $inventoryLabels
     *
     * @return list<string>
     */
    private function proposal(mixed $entries, array $inventoryLabels): array
    {
        if (!is_array($entries)) {
            return [];
        }

        $texts = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (is_string($entry['label'] ?? null)) {
                $texts[] = $entry['label'];
            }

            $key = is_string($entry['key'] ?? null) ? $entry['key'] : null;
            if (null !== $key && isset($inventoryLabels[$key])) {
                $texts[] = $inventoryLabels[$key];
            }
        }

        return $texts;
    }
}
