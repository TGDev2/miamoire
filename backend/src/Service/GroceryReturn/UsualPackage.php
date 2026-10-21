<?php

declare(strict_types=1);

namespace App\Service\GroceryReturn;

use App\Enum\Unit;

/** The last package a household bought of a food, in a readable unit. */
final readonly class UsualPackage
{
    public function __construct(
        public float $quantity,
        public Unit $unit,
    ) {
    }
}
