<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\Unit;
use App\Exception\IncompatibleUnitException;
use App\Service\UnitConverter;
use PHPUnit\Framework\TestCase;

class UnitConverterTest extends TestCase
{
    private UnitConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new UnitConverter();
    }

    public function testConvertsWithinMassFamily(): void
    {
        self::assertSame(1.5, $this->converter->convert(1500, Unit::Gram, Unit::Kilogram));
        self::assertSame(500.0, $this->converter->convert(0.5, Unit::Kilogram, Unit::Gram));
    }

    public function testConvertsWithinVolumeFamily(): void
    {
        self::assertSame(200.0, $this->converter->convert(20, Unit::Centiliter, Unit::Milliliter));
        self::assertSame(1.0, $this->converter->convert(100, Unit::Centiliter, Unit::Liter));
    }

    /** Convertir une masse en volume demanderait une densité qu'on n'a pas. */
    public function testRefusesCrossFamilyConversion(): void
    {
        $this->expectException(IncompatibleUnitException::class);

        $this->converter->convert(100, Unit::Gram, Unit::Liter);
    }

    public function testCountAndServingAreSeparateFamilies(): void
    {
        self::assertFalse($this->converter->canConvert(Unit::Piece, Unit::Portion));
        self::assertFalse($this->converter->canConvert(Unit::Piece, Unit::Gram));
    }

    public function testHumanizePromotesToLargerUnit(): void
    {
        self::assertSame(
            ['quantity' => 1.5, 'unit' => Unit::Kilogram],
            $this->converter->humanize(1500, Unit::Gram),
        );
        self::assertSame(
            ['quantity' => 750.0, 'unit' => Unit::Gram],
            $this->converter->humanize(750, Unit::Gram),
        );
        self::assertSame(
            ['quantity' => 2.0, 'unit' => Unit::Liter],
            $this->converter->humanize(200, Unit::Centiliter),
        );
    }

    public function testHumanizeLeavesDiscreteUnitsAlone(): void
    {
        self::assertSame(
            ['quantity' => 12.0, 'unit' => Unit::Piece],
            $this->converter->humanize(12, Unit::Piece),
        );
    }
}
