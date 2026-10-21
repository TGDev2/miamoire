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

    /**
     * Tout l'inventaire rangé d'un geste, pour le refaire après une absence.
     * Un cas à part : ce n'est pas la mutation d'un lot, et le journal doit
     * pouvoir dire « c'est ici qu'on est reparti de zéro ».
     */
    case InventoryReset = 'inventory_reset';

    /**
     * Every lot of one shopping trip, written together. The journal can say
     * "this is where Tuesday's groceries came in", lot by lot, and a lost
     * answer never writes the trip twice.
     */
    case GroceryReturn = 'grocery_return';

    public function label(): string
    {
        return match ($this) {
            self::RecipeConfirmation => 'Confirmation de recette',
            self::StockItemMutation => 'Modification de lot',
            self::InventoryReset => 'Inventaire remis à zéro',
            self::GroceryReturn => 'Retour de courses',
        };
    }
}
