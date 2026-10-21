<?php

declare(strict_types=1);

namespace App\Service\GroceryReturn;

use App\Entity\ReceiptCapture;

/**
 * What one receipt yielded: the lines to put away, filled in by the
 * resolver, the lines that are not food, and whether the same receipt had
 * already been read.
 */
final readonly class ReceiptReading
{
    /**
     * @param list<array{line: ResolvedGroceryLine, rawLabel: string, lineTotal: ?float}> $lines
     * @param list<array{rawLabel: string, expandedName: string, learned: bool}>          $ignored
     * @param ReceiptCapture|null                                                         $duplicateOf an earlier read of
     *                                                                                                 the same receipt
     */
    public function __construct(
        public ?string $store,
        public ?\DateTimeImmutable $receiptDate,
        public ?float $total,
        public array $lines,
        public array $ignored,
        public ?ReceiptCapture $duplicateOf = null,
    ) {
    }
}
