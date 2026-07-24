<?php

declare(strict_types=1);

namespace App\Enum;

enum StorageLocation: string
{
    case Pantry = 'pantry';
    case Fridge = 'fridge';
    case Freezer = 'freezer';

    public function label(): string
    {
        return match ($this) {
            self::Pantry => 'Placard',
            self::Fridge => 'Réfrigérateur',
            self::Freezer => 'Congélateur',
        };
    }
}
