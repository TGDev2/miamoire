<?php

declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\HouseholdProductVariant;
use App\Enum\Unit;

final readonly class FefoPlan
{
    /** @param list<FefoAllocation> $allocations */
    public function __construct(
        public array $allocations,
        public float $requested,
        public float $allocated,
        public float $shortfall,
        public Unit $unit,
        public ?HouseholdProductVariant $variant = null,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->shortfall <= 0.0001;
    }

    /** Au moins un des lots servis porte une quantité approximative. */
    public function drawsOnEstimatedQuantity(): bool
    {
        foreach ($this->allocations as $allocation) {
            if ($allocation->item->isQuantityEstimated()) {
                return true;
            }
        }

        return false;
    }
}
