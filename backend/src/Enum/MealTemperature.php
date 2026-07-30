<?php

declare(strict_types=1);

namespace App\Enum;

/** Préférence chaud/froid demandée avant la génération de recettes. */
enum MealTemperature: string
{
    case Hot = 'hot';
    case Cold = 'cold';

    public function label(): string
    {
        return match ($this) {
            self::Hot => 'Chaud',
            self::Cold => 'Froid',
        };
    }
}
