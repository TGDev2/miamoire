<?php

declare(strict_types=1);

namespace App\Enum;

enum StockOperationType: string
{
    case RecipeConfirmation = 'recipe_confirmation';

    /**
     * Toute mutation simple d'un lot (consommation, ajout, correction,
     * changement d'unité, ouverture, mise au rebut, archivage, restauration,
     * création). Le détail du geste vit déjà dans `MovementType`, mouvement
     * par mouvement ; ce cas n'a pas besoin de le dupliquer.
     */
    case StockItemMutation = 'stock_item_mutation';

    public function label(): string
    {
        return match ($this) {
            self::RecipeConfirmation => 'Confirmation de recette',
            self::StockItemMutation => 'Modification de lot',
        };
    }
}
