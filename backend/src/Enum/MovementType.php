<?php

declare(strict_types=1);

namespace App\Enum;

enum MovementType: string
{
    case Addition = 'addition';
    case Consumption = 'consumption';
    case Correction = 'correction';
    case Deletion = 'deletion';

    public function label(): string
    {
        return match ($this) {
            self::Addition => 'Ajout',
            self::Consumption => 'Consommation',
            self::Correction => 'Correction',
            self::Deletion => 'Suppression',
        };
    }
}
