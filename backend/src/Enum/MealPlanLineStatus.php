<?php

declare(strict_types=1);

namespace App\Enum;

/** L'état d'un ingrédient d'un repas planifié, une fois les repas plus proches déjà servis. */
enum MealPlanLineStatus: string
{
    case Covered = 'covered';
    case Short = 'short';
    case NeedsReassociation = 'needs_reassociation';

    public function label(): string
    {
        return match ($this) {
            self::Covered => 'Disponible',
            self::Short => 'Manque',
            self::NeedsReassociation => 'À réassocier',
        };
    }
}
