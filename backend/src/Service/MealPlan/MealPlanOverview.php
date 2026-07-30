<?php

declare(strict_types=1);

namespace App\Service\MealPlan;

use App\Service\Inventory\SaveTodayCandidate;

/** La vue complète du plan de repas, jamais persistée — recalculée à chaque lecture. */
final readonly class MealPlanOverview
{
    /**
     * @param list<MealPlanEntryView>    $entries
     * @param list<MealPlanShortfallLine> $shortfallSummary
     * @param list<SaveTodayCandidate>   $unclaimedUrgentItems
     */
    public function __construct(
        public array $entries,
        public array $shortfallSummary,
        public array $unclaimedUrgentItems,
    ) {
    }
}
