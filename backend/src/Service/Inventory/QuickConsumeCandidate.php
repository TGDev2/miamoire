<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\StockItem;
use App\Enum\QuickConsumeReason;

/** Un lot proposé à « J'ai utilisé quelque chose », et pourquoi. */
final readonly class QuickConsumeCandidate
{
    /** @param list<QuickConsumeReason> $reasons */
    public function __construct(
        public StockItem $item,
        public array $reasons,
        public int $score,
    ) {
    }
}
