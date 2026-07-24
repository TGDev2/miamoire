<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Household;
use App\Entity\Product;
use App\Entity\StockItem;
use App\Enum\ProductSource;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Repository\StockItemRepository;
use App\Service\Stock\FefoAllocator;
use App\Service\UnitConverter;
use PHPUnit\Framework\TestCase;

/**
 * Le comportement le plus critique de l'application : quand plusieurs lots du
 * même aliment coexistent, la consommation doit vider en priorité celui qui
 * expire le plus tôt.
 */
class FefoAllocatorTest extends TestCase
{
    private Household $household;

    protected function setUp(): void
    {
        $this->household = new Household('Foyer de test');
    }

    public function testDrainsSoonestExpiringLotFirst(): void
    {
        $early = $this->lot(1, Unit::Liter, '+3 days');
        $late = $this->lot(1, Unit::Liter, '+10 days');

        $plan = $this->allocator([$early, $late])->plan($this->household, 'lait', 1.5, Unit::Liter);

        self::assertTrue($plan->isComplete());
        self::assertCount(2, $plan->allocations);
        self::assertSame($early, $plan->allocations[0]->item, 'Le lot le plus proche de la péremption doit partir en premier.');
        self::assertSame(1.0, $plan->allocations[0]->quantity);
        self::assertSame($late, $plan->allocations[1]->item);
        self::assertSame(0.5, $plan->allocations[1]->quantity);
    }

    public function testReportsShortfallInsteadOfOverdrawing(): void
    {
        $plan = $this->allocator([$this->lot(200, Unit::Gram)])
            ->plan($this->household, 'riz', 500, Unit::Gram);

        self::assertFalse($plan->isComplete());
        self::assertSame(200.0, $plan->allocated);
        self::assertSame(300.0, $plan->shortfall);
    }

    public function testConvertsBetweenCompatibleUnits(): void
    {
        // Demande en grammes, lot stocké en kilogrammes.
        $plan = $this->allocator([$this->lot(2, Unit::Kilogram)])
            ->plan($this->household, 'farine', 500, Unit::Gram);

        self::assertTrue($plan->isComplete());
        self::assertSame(0.5, $plan->allocations[0]->quantity);
        self::assertSame(Unit::Kilogram, $plan->allocations[0]->unit);
    }

    public function testSkipsLotsInAnIncompatibleUnit(): void
    {
        $plan = $this->allocator([$this->lot(3, Unit::Piece)])
            ->plan($this->household, 'tomate', 200, Unit::Gram);

        self::assertSame([], $plan->allocations);
        self::assertSame(200.0, $plan->shortfall);
    }

    public function testStopsOnceTheRequestIsSatisfied(): void
    {
        $first = $this->lot(1000, Unit::Gram);
        $second = $this->lot(1000, Unit::Gram);

        $plan = $this->allocator([$first, $second])->plan($this->household, 'riz', 400, Unit::Gram);

        self::assertCount(1, $plan->allocations, 'Le second lot ne doit pas être entamé inutilement.');
        self::assertSame($first, $plan->allocations[0]->item);
    }

    /** @param list<StockItem> $lots */
    private function allocator(array $lots): FefoAllocator
    {
        $repository = $this->createMock(StockItemRepository::class);
        $repository->method('findAvailableByIngredientKey')->willReturn($lots);

        return new FefoAllocator($repository, new UnitConverter());
    }

    private function lot(float $quantity, Unit $unit, ?string $expiresIn = null): StockItem
    {
        $product = new Product('Produit', 'produit', ProductSource::Manual);
        $item = new StockItem($this->household, $product, $quantity, $unit, StorageLocation::Pantry);

        if (null !== $expiresIn) {
            $item->setExpirationDate(new \DateTimeImmutable($expiresIn));
        }

        return $item;
    }
}
