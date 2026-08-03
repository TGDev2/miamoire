<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\StockItem;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Service\Freshness\EffectiveUseBy;
use App\Service\Freshness\EffectiveUseByCalculator;
use App\Service\Food\FoodTermMatcher;
use App\Service\Freshness\ShelfLifeEstimator;
use App\Service\Product\VariantIdentity;
use App\Service\TextNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * L'échéance qui pilote réellement stock, Cuisiner et notifications : la plus
 * proche de la date imprimée/estimée et de la durée de conservation après
 * ouverture — mais seulement quand cette dernière est connue, jamais inventée.
 */
class EffectiveUseByCalculatorTest extends TestCase
{
    private EffectiveUseByCalculator $calculator;
    private Household $household;

    protected function setUp(): void
    {
        $this->calculator = new EffectiveUseByCalculator(self::estimator());
        $this->household = new Household('Foyer de test');
    }

    private static function estimator(): ShelfLifeEstimator
    {
        $normalizer = new TextNormalizer();

        return new ShelfLifeEstimator($normalizer, new FoodTermMatcher($normalizer));
    }

    public function testAnUnopenedLotUsesOnlyThePrintedDate(): void
    {
        $item = $this->item('Riz', Unit::Gram);
        $item->setExpirationDate($this->inDays(30));

        $effective = $this->calculator->compute($item);

        self::assertSame($this->inDays(30)->format('Y-m-d'), $effective->date?->format('Y-m-d'));
        self::assertSame(EffectiveUseBy::BASIS_PRINTED, $effective->basis);
        self::assertNull($effective->afterOpeningDurationKnown, 'La question ne se pose pas sur un lot non entamé.');
    }

    public function testNoDateAtAllIsHonestlyUnknown(): void
    {
        $item = $this->item('Riz', Unit::Gram);

        $effective = $this->calculator->compute($item);

        self::assertNull($effective->date);
        self::assertSame(EffectiveUseBy::BASIS_UNKNOWN, $effective->basis);
    }

    /** Le lait UHT se garde des mois fermé, quelques jours ouvert : l'ouverture doit primer ici. */
    public function testAnOpenedLotWithAKnownAfterOpeningDurationCanOvertakeAFurtherPrintedDate(): void
    {
        $item = $this->item('Lait UHT', Unit::Liter);
        $item->setExpirationDate($this->inDays(90));
        $item->openNextPackage();

        $effective = $this->calculator->compute($item);

        self::assertSame(EffectiveUseBy::BASIS_AFTER_OPENING, $effective->basis);
        self::assertTrue($effective->afterOpeningDurationKnown);
        self::assertSame($this->inDays(4)->format('Y-m-d'), $effective->date?->format('Y-m-d'));
    }

    /** À l'inverse, une date imprimée plus proche que l'ouverture continue de gagner. */
    public function testThePrintedDateWinsWhenItIsCloserThanAfterOpening(): void
    {
        $item = $this->item('Lait UHT', Unit::Liter);
        $item->setExpirationDate($this->inDays(1));
        $item->openNextPackage();

        $effective = $this->calculator->compute($item);

        self::assertSame(EffectiveUseBy::BASIS_PRINTED, $effective->basis);
        self::assertTrue($effective->afterOpeningDurationKnown, 'La question se posait, elle avait juste perdu.');
        self::assertSame($this->inDays(1)->format('Y-m-d'), $effective->date?->format('Y-m-d'));
    }

    /** Aucune donnée sur la durée après ouverture de cet aliment : on ne l'invente pas. */
    public function testAnOpenedLotWithAnUnknownAfterOpeningDurationFallsBackToThePrintedDate(): void
    {
        $item = $this->item('Machin inconnu', Unit::Piece);
        $item->setExpirationDate($this->inDays(10));
        $item->openNextPackage();

        $effective = $this->calculator->compute($item);

        self::assertSame(EffectiveUseBy::BASIS_PRINTED, $effective->basis);
        self::assertFalse($effective->afterOpeningDurationKnown);
    }

    public function testStatusDistinguishesOpenedFromResealed(): void
    {
        $item = $this->item('Riz', Unit::Gram);
        $item->openNextPackage();
        self::assertSame('opened', $this->calculator->status($item));

        $item->resealOpenPackage();
        self::assertSame('resealed', $this->calculator->status($item));
    }

    private function inDays(int $days): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('today'))->modify(sprintf('+%d days', $days));
    }

    private function item(string $name, Unit $unit): StockItem
    {
        return new StockItem($this->household, $this->variant($name, $unit), 1, $unit, StorageLocation::Fridge);
    }

    private function variant(string $name, Unit $unit): HouseholdProductVariant
    {
        $identity = VariantIdentity::of($name, $unit->family(), []);

        return new HouseholdProductVariant($this->household, $name, $identity, $identity->slugBase());
    }
}
