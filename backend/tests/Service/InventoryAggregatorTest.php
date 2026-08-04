<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\StockItem;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Repository\StockItemRepository;
use App\Service\Food\FoodTermMatcher;
use App\Service\Food\FreshFoodLexicon;
use App\Service\Freshness\EffectiveUseByCalculator;
use App\Service\Freshness\ShelfLifeEstimator;
use App\Service\Inventory\InventoryAggregator;
use App\Service\Product\VariantIdentity;
use App\Service\TextNormalizer;
use App\Service\UnitConverter;
use PHPUnit\Framework\TestCase;

/**
 * Le conditionnement remonté par l'agrégateur : ce qui permet de dire « écoule
 * une boîte » plutôt que « écoule 400 g ».
 *
 * La règle a une part de refus — il vaut mieux ne rien dire que de laisser
 * croire qu'« un exemplaire » a un sens quand il n'en a pas.
 */
class InventoryAggregatorTest extends TestCase
{
    private Household $household;

    protected function setUp(): void
    {
        $this->household = new Household('Foyer de test');
    }

    /** Deux boîtes de 400 g : le cas qui motive toute la fonctionnalité. */
    public function testReportsPackagingWhenEveryLotSharesTheSameSize(): void
    {
        $variant = $this->variant('tomate', Unit::Gram);

        $line = $this->aggregate([
            $this->lot($variant, 800, Unit::Gram, packageSize: 400),
        ])[0];

        self::assertNotNull($line->packaging);
        self::assertSame(2, $line->packaging->count);
        self::assertSame(400.0, $line->packaging->size);
        self::assertSame(Unit::Gram, $line->packaging->unit);
    }

    /**
     * Les exemplaires de plusieurs lots s'additionnent tant qu'ils se ressemblent.
     *
     * La contenance est toujours exprimée dans l'unité de la ligne, pas dans
     * celle des lots : trois boîtes de 400 g font 1,2 kg, et la ligne bascule
     * alors en kilogrammes. Annoncer « 400 g » à côté d'un total en kilos serait
     * deux écritures pour un seul stock, donc une occasion de se contredire.
     */
    public function testAddsUpPackagesAcrossLotsOfTheSameVariant(): void
    {
        $variant = $this->variant('tomate', Unit::Gram);

        $line = $this->aggregate([
            $this->lot($variant, 800, Unit::Gram, packageSize: 400),
            $this->lot($variant, 400, Unit::Gram, packageSize: 400),
        ])[0];

        self::assertNotNull($line->packaging);
        self::assertSame(3, $line->packaging->count);
        self::assertSame(Unit::Kilogram, $line->unit);
        self::assertSame(Unit::Kilogram, $line->packaging->unit);
        self::assertSame(0.4, $line->packaging->size);
    }

    /**
     * Une contenance exprimée dans une autre unité de la même famille reste la
     * même contenance : 0,4 kg et 400 g sont deux écritures d'une seule boîte.
     */
    public function testComparesPackageSizesAcrossUnitsOfTheSameFamily(): void
    {
        $variant = $this->variant('tomate', Unit::Gram);

        $line = $this->aggregate([
            $this->lot($variant, 800, Unit::Gram, packageSize: 400),
            $this->lot($variant, 0.8, Unit::Kilogram, packageSize: 0.4),
        ])[0];

        self::assertNotNull($line->packaging, 'Deux écritures de la même boîte ne sont pas deux conditionnements.');
        self::assertSame(4, $line->packaging->count);
    }

    /**
     * Le refus qui compte : des boîtes de 400 g et des bocaux de 700 g dans le
     * même stock. « Un exemplaire » ne désigne plus rien, on se tait.
     */
    public function testOmitsPackagingWhenLotsDisagreeOnTheirSize(): void
    {
        $variant = $this->variant('tomate', Unit::Gram);

        $line = $this->aggregate([
            $this->lot($variant, 800, Unit::Gram, packageSize: 400),
            $this->lot($variant, 700, Unit::Gram, packageSize: 700),
        ])[0];

        self::assertNull($line->packaging);
    }

    /**
     * Le défaut vu en lançant l'application : des courgettes achetées en vrac
     * s'annonçaient « 1 exemplaire de 600 g ». Un lot vaut un exemplaire par
     * défaut, si bien que tout aliment pesé héritait d'un emballage qu'il n'a
     * jamais eu — et « écouler 1 exemplaire » n'y disait rien de plus
     * qu'« écouler tout le stock », que la quantité exprime déjà.
     */
    public function testOmitsPackagingWhenASingleExemplaireWouldMeanTheWholeStock(): void
    {
        $variant = $this->variant('courgette', Unit::Gram);

        $line = $this->aggregate([
            $this->lot($variant, 600, Unit::Gram, packageSize: 600),
        ])[0];

        self::assertNull($line->packaging);
        self::assertSame(600.0, $line->quantity);
    }

    /**
     * Un aliment compté à la pièce compte déjà ses exemplaires : un second
     * champ ferait dire deux fois la même chose, et permettrait de la contredire.
     */
    public function testOmitsPackagingForFoodAlreadyCountedInPieces(): void
    {
        $variant = $this->variant('yaourt', Unit::Piece);

        $line = $this->aggregate([
            $this->lot($variant, 6, Unit::Piece, packageSize: 1),
        ])[0];

        self::assertNull($line->packaging);
        self::assertSame(6.0, $line->quantity);
    }

    /**
     * Un exemplaire entamé reste un exemplaire, et garde la contenance de ses
     * pareils : 250 g restants dans un sachet de 500 g, ce sont bien deux
     * sachets de 500 g, dont un entamé.
     */
    public function testCountsAnOpenedPackageAsOne(): void
    {
        $variant = $this->variant('farine', Unit::Gram);
        $lot = $this->lot($variant, 1000, Unit::Gram, packageSize: 500);
        $lot->openNextPackage();
        $lot->withdraw(250);

        $line = $this->aggregate([$lot])[0];

        self::assertNotNull($line->packaging);
        self::assertSame(2, $line->packaging->count);
        self::assertSame(500.0, $line->packaging->size);
    }

    /** @param list<StockItem> $lots @return list<\App\Service\Inventory\InventoryLine> */
    private function aggregate(array $lots): array
    {
        $repository = $this->createMock(StockItemRepository::class);
        $repository->method('findAvailable')->willReturn($lots);

        $normalizer = new TextNormalizer();
        $matcher = new FoodTermMatcher($normalizer);

        $aggregator = new InventoryAggregator(
            $repository,
            new UnitConverter(),
            new EffectiveUseByCalculator(new ShelfLifeEstimator($normalizer, $matcher)),
            new FreshFoodLexicon($matcher),
        );

        return $aggregator->aggregate($this->household);
    }

    private function variant(string $key, Unit $unit): HouseholdProductVariant
    {
        $identity = VariantIdentity::of($key, $unit->family(), []);

        return new HouseholdProductVariant($this->household, $key, $identity, $identity->slugBase());
    }

    private function lot(
        HouseholdProductVariant $variant,
        float $quantity,
        Unit $unit,
        float $packageSize,
    ): StockItem {
        $item = new StockItem($this->household, $variant, $quantity, $unit, StorageLocation::Pantry);
        $item->setPackageSize($packageSize);
        $item->setNumberOfPackages((int) round($quantity / $packageSize));

        return $item;
    }
}
