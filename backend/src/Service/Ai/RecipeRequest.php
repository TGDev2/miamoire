<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Enum\MealTemperature;

/** Les contraintes saisies dans l'écran « Que cuisiner ? ». */
final readonly class RecipeRequest
{
    /**
     * @param list<string> $equipment  four, poele, casserole, micro-ondes...
     * @param list<string> $exclusions allergies et régimes
     */
    public function __construct(
        public int $people = 2,
        public ?string $mealType = null,
        public ?MealTemperature $mealTemperature = null,
        public ?int $maxMinutes = null,
        public array $equipment = [],
        public array $exclusions = [],
        public bool $allowExtraIngredients = false,
        /**
         * Clés d'inventaire d'aliments à privilégier, dans l'ordre — jusqu'à 3.
         *
         * Facultatif : demander des recettes sans rien imposer reste le cas
         * courant. Renseignées, la PREMIÈRE est une contrainte — vérifiée sur
         * chaque recette renvoyée, comme l'ancien `priorityKey` — les suivantes
         * (au plus 2) ne sont que des préférences : une recette qui les ignore
         * n'est jamais écartée pour cette seule raison.
         *
         * @var list<string>
         */
        public array $priorityKeys = [],
    ) {
    }
}
