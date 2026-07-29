<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\StockItem;
use App\Enum\UrgencyReason;

/** Un lot proposé par « Aujourd'hui », et pourquoi il faut s'en occuper. */
final readonly class SaveTodayCandidate
{
    /** @param list<UrgencyReason> $reasons */
    public function __construct(
        public StockItem $item,
        public array $reasons,
    ) {
    }
}
