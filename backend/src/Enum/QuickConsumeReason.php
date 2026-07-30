<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Pourquoi un lot est proposé à « J'ai utilisé quelque chose ».
 *
 * `weight()` pondère `Opened` le plus fort : un exemplaire déjà entamé est
 * l'indice le plus direct d'un usage en cours. `RecentlyViewed` pèse le moins
 * lourd — avoir consulté une fiche ne veut pas dire l'avoir utilisée.
 */
enum QuickConsumeReason: string
{
    case Opened = 'opened';
    case RecentlyConsumed = 'recently_consumed';
    case RecentlyUsedInRecipe = 'recently_used_in_recipe';
    case RecentlyViewed = 'recently_viewed';

    public function label(): string
    {
        return match ($this) {
            self::Opened => 'Entamé',
            self::RecentlyConsumed => 'Consommé récemment',
            self::RecentlyUsedInRecipe => 'Utilisé dans une recette récente',
            self::RecentlyViewed => 'Consulté récemment',
        };
    }

    public function weight(): int
    {
        return match ($this) {
            self::Opened => 3,
            self::RecentlyConsumed, self::RecentlyUsedInRecipe => 2,
            self::RecentlyViewed => 1,
        };
    }
}
