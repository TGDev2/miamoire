<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Pourquoi un lot est proposé à « Vérifier mon stock ».
 *
 * `weight()` pondère volontairement `Expired`/`ExpiringSoon` moins que les
 * trois autres : une péremption proche est déjà signalée par la notification
 * locale et le filtre « Bientôt périmés » de l'écran stock. La valeur propre
 * de cette file est de faire remonter ce qu'aucun autre mécanisme ne signale
 * — un lot entamé, une quantité estimée, un lot qu'on n'a pas retouché depuis
 * plus longtemps que sa conservation probable.
 */
enum ReviewReason: string
{
    case Opened = 'opened';
    case QuantityEstimated = 'quantity_estimated';
    case Expired = 'expired';
    case ExpiringSoon = 'expiring_soon';
    case Stale = 'stale';

    public function label(): string
    {
        return match ($this) {
            self::Opened => 'Entamé',
            self::QuantityEstimated => 'Quantité estimée',
            self::Expired => 'Date dépassée',
            self::ExpiringSoon => 'Bientôt périmé',
            self::Stale => 'Pas retouché depuis longtemps',
        };
    }

    public function weight(): int
    {
        return match ($this) {
            self::Stale, self::QuantityEstimated => 3,
            self::Opened => 2,
            self::Expired, self::ExpiringSoon => 1,
        };
    }
}
