<?php

declare(strict_types=1);

namespace App\Service\Ai;

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
        public ?int $maxMinutes = null,
        public array $equipment = [],
        public array $exclusions = [],
        public bool $allowExtraIngredients = false,
        /**
         * Clé d'inventaire d'un aliment à utiliser impérativement — `tomate-conserve`.
         *
         * Facultatif : demander des recettes sans rien imposer reste le cas
         * courant. Renseigné, ce n'est pas une préférence mais une contrainte,
         * vérifiée sur chaque recette renvoyée.
         */
        public ?string $priorityKey = null,
    ) {
    }
}
