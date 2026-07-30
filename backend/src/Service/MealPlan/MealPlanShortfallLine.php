<?php

declare(strict_types=1);

namespace App\Service\MealPlan;

use App\Enum\Unit;

/**
 * Un manque agrégé, déjà déduplique par variante (ou par libellé pour les
 * lignes non résolues) sur l'ensemble du plan — jamais un manque par ligne
 * d'entrée : voir `MealPlanService::overview()` pour la raison.
 */
final readonly class MealPlanShortfallLine
{
    public function __construct(
        public string $label,
        public float $quantity,
        public Unit $unit,
    ) {
    }
}
