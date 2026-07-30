<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Pourquoi le conseiller avant les courses retient une variante.
 *
 * Un seul motif par variante (voir `ShoppingAdvisorSelector`) : contrairement à
 * `UrgencyReason`, ces quatre cas ne se cumulent jamais sur la même ligne.
 */
enum ShoppingAdviceKind: string
{
    case UseSoonDontAdd = 'use_soon_dont_add';
    case SufficientForPlannedMeals = 'sufficient_for_planned_meals';
    case DontRebuy = 'dont_rebuy';
    case RunningOut = 'running_out';

    public function label(): string
    {
        return match ($this) {
            self::UseSoonDontAdd => 'À consommer bientôt',
            self::SufficientForPlannedMeals => 'Suffisant pour les repas prévus',
            self::DontRebuy => 'Ne pas racheter',
            self::RunningOut => 'Bientôt épuisé',
        };
    }
}
