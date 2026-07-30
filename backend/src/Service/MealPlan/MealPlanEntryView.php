<?php

declare(strict_types=1);

namespace App\Service\MealPlan;

use App\Entity\MealPlanEntry;
use App\Service\Ai\RecipePlan;

/** Une entrée du plan, avec son plan de préparation et ses lignes de réservation recalculés à l'instant. */
final readonly class MealPlanEntryView
{
    /** @param list<MealPlanLine> $lines */
    public function __construct(
        public MealPlanEntry $entry,
        public ?RecipePlan $plan,
        public array $lines,
    ) {
    }
}
