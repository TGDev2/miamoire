<?php

declare(strict_types=1);

namespace App\Service\Shopping;

use App\Enum\ConsumptionConfidence;

/** Le débit quotidien estimé d'une variante, en unité de base, et à quel point s'y fier. */
final readonly class ConsumptionRateEstimate
{
    public function __construct(
        public float $dailyRateBase,
        public ConsumptionConfidence $confidence,
        public int $sampleCount,
        public int $observedDays,
    ) {
    }
}
