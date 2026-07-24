<?php

declare(strict_types=1);

namespace App\Enum;

enum Unit: string
{
    case Gram = 'g';
    case Kilogram = 'kg';
    case Milliliter = 'ml';
    case Centiliter = 'cl';
    case Liter = 'l';
    case Piece = 'piece';
    case Portion = 'portion';

    public function family(): UnitFamily
    {
        return match ($this) {
            self::Gram, self::Kilogram => UnitFamily::Mass,
            self::Milliliter, self::Centiliter, self::Liter => UnitFamily::Volume,
            self::Piece => UnitFamily::Count,
            self::Portion => UnitFamily::Serving,
        };
    }

    /** Unité pivot de la famille : toutes les conversions passent par elle. */
    public function baseUnit(): self
    {
        return match ($this->family()) {
            UnitFamily::Mass => self::Gram,
            UnitFamily::Volume => self::Milliliter,
            UnitFamily::Count => self::Piece,
            UnitFamily::Serving => self::Portion,
        };
    }

    /** Combien d'unités de base vaut 1 unité de ce type. */
    public function factorToBase(): float
    {
        return match ($this) {
            self::Gram, self::Milliliter, self::Piece, self::Portion => 1.0,
            self::Kilogram, self::Liter => 1000.0,
            self::Centiliter => 10.0,
        };
    }

    /**
     * Une unité dénombrable se manipule naturellement à l'unité (œufs, yaourts) :
     * l'interface propose alors directement -1 / +1.
     */
    public function isDiscrete(): bool
    {
        return UnitFamily::Count === $this->family() || UnitFamily::Serving === $this->family();
    }

    public function label(): string
    {
        return match ($this) {
            self::Gram => 'g',
            self::Kilogram => 'kg',
            self::Milliliter => 'ml',
            self::Centiliter => 'cl',
            self::Liter => 'L',
            self::Piece => 'unité',
            self::Portion => 'portion',
        };
    }

    /** @return list<self> */
    public static function compatibleWith(self $unit): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $candidate) => $candidate->family() === $unit->family(),
        ));
    }
}
