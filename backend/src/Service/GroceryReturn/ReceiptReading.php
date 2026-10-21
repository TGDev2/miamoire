<?php

declare(strict_types=1);

namespace App\Service\GroceryReturn;

/**
 * What one receipt yielded: the lines to put away, filled in by the
 * resolver, and the lines that are not food.
 */
final readonly class ReceiptReading
{
    /**
     * @param list<array{line: ResolvedGroceryLine, rawLabel: string, lineTotal: ?float}> $lines
     * @param list<array{rawLabel: string, expandedName: string, learned: bool}>          $ignored
     */
    public function __construct(
        public ?string $store,
        public ?\DateTimeImmutable $receiptDate,
        public ?float $total,
        public array $lines,
        public array $ignored,
    ) {
    }
}
