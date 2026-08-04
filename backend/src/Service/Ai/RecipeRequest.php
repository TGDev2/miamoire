<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Enum\Equipment;
use App\Enum\MealTemperature;

/** Les contraintes saisies dans l'écran « Que cuisiner ? ». */
final readonly class RecipeRequest
{
    /**
     * @param list<Equipment> $equipment  ce dont le foyer dispose réellement ;
     *                                    une valeur inconnue est écartée au bord
     * @param list<string>    $exclusions allergies et régimes
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
         * Aliments à privilégier, dans l'ordre — jusqu'à 3.
         *
         * Facultatif : demander des recettes sans rien imposer reste le cas
         * courant. Renseignés, le PREMIER est une contrainte — vérifiée sur
         * chaque recette renvoyée, comme l'ancien `priorityKey` — les suivants
         * (au plus 2) ne sont que des préférences : une recette qui les ignore
         * n'est jamais écartée pour cette seule raison.
         *
         * Chacun peut porter une quantité à écouler, elle aussi facultative
         * ({@see PriorityChoice}). Seule celle du premier est vérifiée après
         * génération : la quantité d'une simple préférence reste un souhait,
         * comme la préférence elle-même.
         *
         * @var list<PriorityChoice>
         */
        public array $priorities = [],
    ) {
    }
}
