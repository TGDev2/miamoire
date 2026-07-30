<?php

declare(strict_types=1);

namespace App\Service\MealPlan;

use App\Enum\MealPlanLineStatus;
use App\Enum\Unit;

/** L'état d'un ingrédient d'un repas planifié, calculé à l'instant de l'appel. */
final readonly class MealPlanLine
{
    /** @param list<MealPlanCrossReference> $alsoClaimedBy */
    public function __construct(
        public string $key,
        public string $label,
        public float $requestedQuantity,
        public Unit $requestedUnit,
        public MealPlanLineStatus $status,
        public float $shortfall,
        public ?string $detail,
        public array $alsoClaimedBy,
    ) {
    }
}
