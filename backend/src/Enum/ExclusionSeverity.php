<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Ce que signifie vraiment une exclusion alimentaire pour le foyer.
 *
 * « Lait » et « coriandre » dans la même liste plate laissaient croire qu'une
 * préférence méritait la même rigueur qu'une allergie. Ce n'est plus le cas :
 * seule `Allergy` déclenche le refus de présenter un produit comme sûr quand
 * sa composition est inconnue.
 */
enum ExclusionSeverity: string
{
    case Allergy = 'allergy';
    case Diet = 'diet';
    case Preference = 'preference';

    public function label(): string
    {
        return match ($this) {
            self::Allergy => 'Allergie stricte',
            self::Diet => 'Régime',
            self::Preference => 'Préférence',
        };
    }
}
