<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\StockItem;
use App\Enum\ReviewReason;

/** Un lot proposé à « Vérifier mon stock », et pourquoi. */
final readonly class ReviewCandidate
{
    /** @param list<ReviewReason> $reasons */
    public function __construct(
        public StockItem $item,
        public array $reasons,
        public int $score,
    ) {
    }
}
