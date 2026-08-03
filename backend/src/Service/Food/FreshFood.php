<?php

declare(strict_types=1);

namespace App\Service\Food;

use App\Enum\Unit;

/**
 * Ce que le lexique sait d'un aliment frais : comment il se compte, et ce que
 * pèse à peu près l'un d'eux.
 *
 * Le poids indicatif ne sert JAMAIS à convertir : `UnitConverter` refuse
 * délibérément de passer d'une famille d'unité à une autre, et ce refus protège
 * l'inventaire. Il n'est transmis qu'au générateur de recettes, pour qu'il puisse
 * écrire « environ 500 g de courgettes » dans ses étapes tout en proposant
 * « 2 pièces » dans sa demande de déduction de stock.
 */
final readonly class FreshFood
{
    public function __construct(
        /** Le terme du lexique effectivement reconnu — « pomme de terre », pas « pomme ». */
        public string $term,
        public Unit $naturalUnit,
        public ?int $approxPieceWeight = null,
    ) {
    }
}
