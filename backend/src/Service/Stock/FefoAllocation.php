<?php

declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\StockItem;
use App\Enum\Unit;

/** Une ligne de prélèvement : « retirer 300 g du lot qui expire le 27/07 ». */
final readonly class FefoAllocation
{
    public function __construct(
        public StockItem $item,
        public float $quantity,
        public Unit $unit,
    ) {
    }
}
