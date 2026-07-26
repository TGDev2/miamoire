<?php

declare(strict_types=1);

namespace App\Enum;

enum StockOperationType: string
{
    case RecipeConfirmation = 'recipe_confirmation';

    public function label(): string
    {
        return match ($this) {
            self::RecipeConfirmation => 'Confirmation de recette',
        };
    }
}
