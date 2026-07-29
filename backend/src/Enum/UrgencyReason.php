<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Pourquoi un lot est proposé par « Aujourd'hui ».
 *
 * Distinct de {@see ReviewReason} malgré le recoupement partiel des faits
 * sous-jacents : celui-ci répond à « cette donnée est-elle fiable ? »
 * (vérifier mon stock), celui-là répond à « ce lot doit-il être utilisé
 * maintenant ? » (aujourd'hui) — deux questions différentes, deux
 * fonctionnalités indépendantes.
 */
enum UrgencyReason: string
{
    case Expired = 'expired';
    case ExpiringSoon = 'expiring_soon';
    case Opened = 'opened';

    public function label(): string
    {
        return match ($this) {
            self::Expired => 'Date dépassée',
            self::ExpiringSoon => 'Bientôt périmé',
            self::Opened => 'Entamé',
        };
    }
}
