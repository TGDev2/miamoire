<?php

declare(strict_types=1);

namespace App\Enum;

enum ProductSource: string
{
    case OpenFoodFacts = 'openfoodfacts';
    case Manual = 'manual';
}
