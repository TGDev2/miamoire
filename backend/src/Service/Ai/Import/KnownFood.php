<?php

declare(strict_types=1);

namespace App\Service\Ai\Import;

use App\Enum\UnitFamily;

/** A food of the household, as an imported recipe line may name it. */
final readonly class KnownFood
{
    public function __construct(
        /** The variant slug — the `key` of the exported data. */
        public string $key,
        /** The culinary label — the `ingredient` of the exported data. */
        public string $label,
        public UnitFamily $family,
        /**
         * Only foods in stock were in the exported data, so only they can be
         * found by their label. A known key is honoured even when out of stock.
         */
        public bool $inStock,
    ) {
    }
}
