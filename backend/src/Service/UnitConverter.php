<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Unit;
use App\Exception\IncompatibleUnitException;

/**
 * Conversions à l'intérieur d'une même famille d'unités.
 *
 * On refuse volontairement de deviner une densité : convertir 200 g de crème
 * en cl demanderait une donnée qu'on n'a pas, et une erreur silencieuse ici
 * corromprait l'inventaire.
 */
class UnitConverter
{
    public function canConvert(Unit $from, Unit $to): bool
    {
        return $from->family() === $to->family();
    }

    public function convert(float $quantity, Unit $from, Unit $to): float
    {
        if ($from === $to) {
            return $quantity;
        }

        if (!$this->canConvert($from, $to)) {
            throw new IncompatibleUnitException($from, $to);
        }

        return $quantity * $from->factorToBase() / $to->factorToBase();
    }

    public function toBase(float $quantity, Unit $unit): float
    {
        return $quantity * $unit->factorToBase();
    }

    /**
     * Choisit l'unité la plus lisible pour l'affichage : 1500 g devient 1,5 kg.
     *
     * @return array{quantity: float, unit: Unit}
     */
    public function humanize(float $quantity, Unit $unit): array
    {
        $base = $this->toBase($quantity, $unit);

        $preferred = match ($unit->family()) {
            \App\Enum\UnitFamily::Mass => $base >= 1000 ? Unit::Kilogram : Unit::Gram,
            \App\Enum\UnitFamily::Volume => $base >= 1000 ? Unit::Liter : Unit::Milliliter,
            default => $unit,
        };

        return [
            'quantity' => round($this->convert($quantity, $unit, $preferred), 2),
            'unit' => $preferred,
        ];
    }
}
