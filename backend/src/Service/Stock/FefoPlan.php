<?php

declare(strict_types=1);

namespace App\Service\Stock;

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
    ) {
    }

    public function isComplete(): bool
    {
        return $this->shortfall <= 0.0001;
    }
}
