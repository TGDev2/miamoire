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

    public function label(): string
    {
        return match ($this) {
            self::Mass => 'Masse',
            self::Volume => 'Volume',
            self::Count => 'À l\'unité',
            self::Serving => 'En portions',
        };
    }

    /**
     * Fragment lisible distinguant deux variantes du même aliment comptées
     * différemment : `tomate` au poids et `tomate-piece` à l'unité.
     */
    public function slug(): string
    {
        return match ($this) {
            self::Mass => 'poids',
            self::Volume => 'volume',
            self::Count => 'piece',
            self::Serving => 'portion',
        };
    }
}
