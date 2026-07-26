<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Service\Inventory\InventoryLine;

/**
 * Écarte les recettes qui n'utilisent pas l'aliment imposé.
 *
 * Quand l'utilisateur désigne un produit à écouler — une botte de radis, un
 * reste de crème — la consigne au modèle ne suffit pas : il en propose deux sur
 * trois qui l'utilisent, et la troisième arrive quand même. Une contrainte qui
 * n'est vraie que la plupart du temps n'est pas une contrainte (leçon n° 14).
 *
 * Le critère est la **clé** de la variante, jamais son libellé : le modèle
 * recopie les clés de l'inventaire, et deux libellés proches — « Tomates » et
 * « Tomates (en conserve) » — désignent des stocks différents.
 */
class RequiredIngredientFilter
{
    /**
     * @param list<array<string, mixed>> $recipes
     *
     * @return array{recipes: list<array<string, mixed>>, rejected: list<array{recipeName: string, reason: string}>}
     */
    public function apply(array $recipes, ?InventoryLine $required): array
    {
        if (null === $required) {
            return ['recipes' => array_values($recipes), 'rejected' => []];
        }

        $kept = [];
        $rejected = [];

        foreach ($recipes as $recipe) {
            if ($this->uses($recipe, $required->key)) {
                $kept[] = $recipe;
                continue;
            }

            $rejected[] = [
                'recipeName' => is_string($recipe['recipeName'] ?? null)
                    ? $recipe['recipeName']
                    : 'Proposition sans nom',
                'reason' => sprintf('n\'utilise pas %s', $required->label),
            ];
        }

        return ['recipes' => $kept, 'rejected' => $rejected];
    }

    /**
     * Deux endroits font foi, et pour deux raisons différentes : la proposition
     * de déduction dit ce qui sortira réellement du stock, la liste des
     * ingrédients dit ce que la recette met dans l'assiette. Un aliment présent
     * dans l'une sans l'autre est tout de même employé.
     *
     * @param array<string, mixed> $recipe
     */
    private function uses(array $recipe, string $key): bool
    {
        foreach (['stockConsumptionProposal', 'ingredientsFromInventory'] as $field) {
            $entries = $recipe[$field] ?? null;

            if (!is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (is_array($entry) && ($entry['key'] ?? null) === $key) {
                    return true;
                }
            }
        }

        return false;
    }
}
