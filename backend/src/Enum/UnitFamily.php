<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Familles d'unités entre lesquelles une conversion est possible.
 * On ne convertit jamais d'une famille à une autre (100 g de farine != 100 ml).
 */
enum UnitFamily: string
{
    case Mass = 'mass';
    case Volume = 'volume';
    case Count = 'count';
    case Serving = 'serving';
}
