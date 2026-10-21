<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A grocery return refused before anything was written.
 *
 * Thirty lines are validated together, and the answer names every line at
 * fault rather than the first one: the person fixes them all, then confirms
 * again with the same operation key — the key was never reserved.
 */
class GroceryReturnRejectedException extends StockException
{
    /** @param list<array{lineKey: string, message: string}> $lines */
    public function __construct(private readonly array $lines)
    {
        parent::__construct(1 === count($lines)
            ? 'Une ligne du retour de courses est à corriger.'
            : sprintf('%d lignes du retour de courses sont à corriger.', count($lines)));
    }

    /** @return list<array{lineKey: string, message: string}> */
    public function getLines(): array
    {
        return $this->lines;
    }
}
