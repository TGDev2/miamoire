<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Le créneau d'un repas planifié — distinct du `mealType` texte libre de la
 * génération de recettes (`RecipeRequest`/`/api/reference`), qu'il ne remplace
 * pas : celui-là catégorise un repas, celui-ci ordonne une journée du plan.
 */
enum MealSlot: string
{
    case Breakfast = 'breakfast';
    case Lunch = 'lunch';
    case Dinner = 'dinner';
    case Snack = 'snack';

    public function label(): string
    {
        return match ($this) {
            self::Breakfast => 'Petit-déjeuner',
            self::Lunch => 'Déjeuner',
            self::Dinner => 'Dîner',
            self::Snack => 'Goûter',
        };
    }

    /** Ordre chronologique dans une même journée — pas l'ordre alphabétique des valeurs. */
    public function order(): int
    {
        return match ($this) {
            self::Breakfast => 0,
            self::Lunch => 1,
            self::Dinner => 2,
            self::Snack => 3,
        };
    }
}
