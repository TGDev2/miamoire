<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Approximations acceptées quand l'utilisateur ne sait pas exactement ce qu'il reste.
 * Mieux vaut une estimation qu'un inventaire abandonné.
 */
enum FillLevel: string
{
    case Full = 'full';
    case Half = 'half';
    case AlmostEmpty = 'almost_empty';
    case Unknown = 'unknown';

    /** Fraction de la quantité initiale, ou null si l'utilisateur ne sait pas. */
    public function ratio(): ?float
    {
        return match ($this) {
            self::Full => 1.0,
            self::Half => 0.5,
            self::AlmostEmpty => 0.15,
            self::Unknown => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Plein',
            self::Half => 'Environ la moitié',
            self::AlmostEmpty => 'Presque vide',
            self::Unknown => 'Quantité inconnue',
        };
    }
}
