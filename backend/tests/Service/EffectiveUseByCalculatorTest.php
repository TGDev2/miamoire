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

    /**
     * Freezing stops the clock: a printed date that has passed in the freezer
     * is a quality question, not a safety one. The printed date is kept.
     */
    public function testInTheFreezerAPassedPrintedDateIsNoLongerExpired(): void
    {
        $item = $this->frozenItem('Saucisses de Toulouse', printedInDays: -30, purchasedInDays: -20);

        $effective = $this->calculator->compute($item);

        self::assertSame(EffectiveUseBy::BASIS_FROZEN, $effective->basis);
        self::assertFalse($effective->isExpired());
        self::assertFalse($effective->isExpiringSoon());
        self::assertFalse($effective->isPastBest());
        // Sausages keep 60 days in the freezer, counted from the purchase.
        self::assertSame($this->inDays(40)->format('Y-m-d'), $effective->date?->format('Y-m-d'));
        self::assertSame($this->inDays(-30)->format('Y-m-d'), $item->getExpirationDate()?->format('Y-m-d'));
        self::assertNotSame('expired', $this->calculator->status($item));
    }

    /** A frozen food's own printed date (a "surgelé" best-before) is never shortened. */
    public function testInTheFreezerALaterPrintedDateWins(): void
    {
        $item = $this->frozenItem('Steak haché', printedInDays: 300, purchasedInDays: -10);

        $effective = $this->calculator->compute($item);

        self::assertSame(EffectiveUseBy::BASIS_FROZEN, $effective->basis);
        self::assertSame($this->inDays(300)->format('Y-m-d'), $effective->date?->format('Y-m-d'));
    }

    /** The freezer clock starts on the day of freezing when it is known, not on the purchase. */
    public function testTheFreezerClockStartsWhenTheLotWasFrozen(): void
    {
        $item = $this->frozenItem('Steak haché', printedInDays: 2, purchasedInDays: -40);
        $item->setFrozenAt($this->inDays(-1));

        $effective = $this->calculator->compute($item);

        self::assertSame($this->inDays(89)->format('Y-m-d'), $effective->date?->format('Y-m-d'));
    }

    /** Past its freezer quality date, a lot is "best eaten soon", never "expired". */
    public function testPastItsFreezerQualityDateALotIsPastBestNotExpired(): void
    {
        $item = $this->frozenItem('Steak haché', printedInDays: -190, purchasedInDays: -200);

        $effective = $this->calculator->compute($item);

        self::assertTrue($effective->isPastBest());
        self::assertFalse($effective->isExpired());
        self::assertFalse($effective->isExpiringSoon());
        self::assertSame('frozen_past_best', $this->calculator->status($item));
    }

    /** Freezing does not undo a printed date that had already passed on the day of freezing. */
    public function testALotFrozenAfterItsPrintedDateStaysExpired(): void
    {
        $item = $this->frozenItem('Blanc de poulet', printedInDays: -10, purchasedInDays: -15);
        $item->setFrozenAt($this->inDays(-5));

        $effective = $this->calculator->compute($item);

        self::assertSame(EffectiveUseBy::BASIS_PRINTED, $effective->basis);
        self::assertTrue($effective->isExpired());
        self::assertSame('expired', $this->calculator->status($item));
    }

    /** Frozen on its last day is in time: the date had not passed yet. */
    public function testALotFrozenOnItsLastDayIsInTime(): void
    {
        $item = $this->frozenItem('Blanc de poulet', printedInDays: -5, purchasedInDays: -8);
        $item->setFrozenAt($this->inDays(-5)->setTime(18, 30));

        self::assertSame(EffectiveUseBy::BASIS_FROZEN, $this->calculator->compute($item)->basis);
    }

    /** An estimated date is a guess: freezing after it never turns the lot into "expired". */
    public function testAnEstimatedDateNeverMakesAFreezingTooLate(): void
    {
        $item = $this->frozenItem('Courgette', printedInDays: -10, purchasedInDays: -15);
        $item->setEstimatedExpirationDate($this->inDays(-10));
        $item->setFrozenAt($this->inDays(-5));

        self::assertSame(EffectiveUseBy::BASIS_FROZEN, $this->calculator->compute($item)->basis);
    }

    /** An opened ham that was then frozen does not go off four days after opening. */
    public function testTheFreezerSuspendsTheAfterOpeningClock(): void
    {
        $item = $this->frozenItem('Jambon blanc', printedInDays: 20, purchasedInDays: -12);
        $item->openNextPackage();
        $this->setOpenedAt($item, $this->inDays(-10));

        $effective = $this->calculator->compute($item);

        self::assertSame(EffectiveUseBy::BASIS_FROZEN, $effective->basis);
        self::assertFalse($effective->isExpired());
    }

    /** Out of the freezer, a thawed lot is eaten within 24 hours, whatever its printed date said. */
    public function testAThawedLotMustBeEatenWithinADay(): void
    {
        $item = $this->frozenItem('Blanc de poulet', printedInDays: -20, purchasedInDays: -40);
        $item->setFrozenAt($this->inDays(-35));
        $item->setStorageLocation(StorageLocation::Fridge);
        $item->setThawedAt(new \DateTimeImmutable());

        $effective = $this->calculator->compute($item);

        self::assertSame(EffectiveUseBy::BASIS_THAWED, $effective->basis);
        self::assertSame($this->inDays(1)->format('Y-m-d'), $effective->date?->format('Y-m-d'));
        self::assertFalse($effective->isExpired());
        self::assertTrue($effective->isExpiringSoon());
    }

    /** Thawing a lot that was frozen too late does not hide its passed date either. */
    public function testThawingALotFrozenTooLateKeepsItExpired(): void
    {
        $item = $this->frozenItem('Blanc de poulet', printedInDays: -10, purchasedInDays: -15);
        $item->setFrozenAt($this->inDays(-5));
        $item->setStorageLocation(StorageLocation::Fridge);
        $item->setThawedAt(new \DateTimeImmutable());

        self::assertTrue($this->calculator->compute($item)->isExpired());
    }

    private function inDays(int $days): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('today'))->modify(sprintf('%+d days', $days));
    }

    private function item(string $name, Unit $unit): StockItem
    {
        return new StockItem($this->household, $this->variant($name, $unit), 1, $unit, StorageLocation::Fridge);
    }

    private function frozenItem(string $name, int $printedInDays, int $purchasedInDays): StockItem
    {
        $item = new StockItem($this->household, $this->variant($name, Unit::Gram), 500, Unit::Gram, StorageLocation::Freezer);
        $item->setExpirationDate($this->inDays($printedInDays));
        $item->setPurchaseDate($this->inDays($purchasedInDays));

        return $item;
    }

    private function setOpenedAt(StockItem $item, \DateTimeImmutable $openedAt): void
    {
        (new \ReflectionProperty(StockItem::class, 'openedAt'))->setValue($item, $openedAt);
    }

    private function variant(string $name, Unit $unit): HouseholdProductVariant
    {
        $identity = VariantIdentity::of($name, $unit->family(), []);

        return new HouseholdProductVariant($this->household, $name, $identity, $identity->slugBase());
    }
}
