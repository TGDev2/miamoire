<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Service\Food\FoodCutLexicon;
use App\Service\Inventory\InventoryLine;

/**
 * Keeps `assumedCut` for real guesses only.
 *
 * The recipe screen shows each guess as "Forme supposée : X — corriger". The
 * model also "assumes" cuts it was given (minced steak, sliced onions) or
 * cuts the food is never bought in, and every one of those asks the user to
 * check something that needs no checking. A guess is dropped when the line
 * already has a cut, or when the food is known and cannot take that cut.
 * On a food the lexicon does not know, any cut is possible: the guess stays.
 */
class AssumedCutFilter
{
    public function __construct(private readonly FoodCutLexicon $lexicon)
    {
    }

    /**
     * @param list<array<string, mixed>> $recipes
     * @param list<InventoryLine>        $inventory
     *
     * @return list<array<string, mixed>>
     */
    public function apply(array $recipes, array $inventory): array
    {
        $lines = [];
        foreach ($inventory as $line) {
            $lines[$line->key] = $line;
        }

        return array_map(function (array $recipe) use ($lines): array {
            if (!is_array($recipe['ingredientsFromInventory'] ?? null)) {
                return $recipe;
            }

            $recipe['ingredientsFromInventory'] = array_map(
                fn (mixed $ingredient): mixed => is_array($ingredient) ? $this->checked($ingredient, $lines) : $ingredient,
                $recipe['ingredientsFromInventory'],
            );

            return $recipe;
        }, $recipes);
    }

    /**
     * @param array<string, mixed>         $ingredient
     * @param array<string, InventoryLine> $lines
     *
     * @return array<string, mixed>
     */
    private function checked(array $ingredient, array $lines): array
    {
        $guess = $ingredient['assumedCut'] ?? null;
        $line = $lines[$ingredient['key'] ?? ''] ?? null;

        if (!is_string($guess) || 'none' === $guess || null === $line) {
            return $ingredient;
        }

        $plausible = $this->lexicon->plausibleCuts($line->name);
        $unfounded = null !== $line->cut
            || (null !== $plausible && !in_array($guess, array_map(static fn ($cut) => $cut->value, $plausible), true));

        return $unfounded ? ['assumedCut' => 'none'] + $ingredient : $ingredient;
    }
}
