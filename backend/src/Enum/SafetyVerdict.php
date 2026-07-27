<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Ce qu'on sait vraiment de la présence d'un allergène dans un produit.
 *
 * `Unknown` n'est pas un défaut technique qu'on comble en attendant mieux :
 * c'est un état honnête. Open Food Facts est incomplet et collaboratif — tant
 * qu'un produit n'a pas de fiche allergènes, prétendre qu'il est sûr serait un
 * mensonge, pas une simplification.
 */
enum SafetyVerdict: string
{
    case Incompatible = 'incompatible';
    case Compatible = 'compatible';
    case Unknown = 'unknown';
}
