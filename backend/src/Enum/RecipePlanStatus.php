<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * L'état réel d'une recette, recalculé au moment où on la regarde — jamais
 * figé dans le JSON enregistré.
 *
 * L'ordre des cas ci-dessous est aussi l'ordre de gravité : quand plusieurs
 * lignes d'une même recette ont des statuts différents, le pire l'emporte
 * (voir `RecipePlan::overallStatus()`).
 */
enum RecipePlanStatus: string
{
    case Incompatible = 'incompatible';
    case SafetyUnknown = 'safety_unknown';
    case NeedsReassociation = 'needs_reassociation';
    case NeedsShopping = 'needs_shopping';
    case Ready = 'ready';

    /** Plus petit indice = plus grave. */
    public function severity(): int
    {
        return match ($this) {
            self::Incompatible => 0,
            self::SafetyUnknown => 1,
            self::NeedsReassociation => 2,
            self::NeedsShopping => 3,
            self::Ready => 4,
        };
    }

    public function worseOf(self $other): self
    {
        return $this->severity() <= $other->severity() ? $this : $other;
    }
}
