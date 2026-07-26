<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\StockItem;
use App\Enum\ProductTrait;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Repository\StockItemRepository;
use App\Service\Product\VariantIdentity;
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
        $variant = $this->variant('lait', Unit::Liter);
        $early = $this->lot($variant, 1, Unit::Liter, '+3 days');
        $late = $this->lot($variant, 1, Unit::Liter, '+10 days');

        $plan = $this->allocator([$early, $late])->plan($variant, 1.5, Unit::Liter);

        self::assertTrue($plan->isComplete());
        self::assertCount(2, $plan->allocations);
        self::assertSame($early, $plan->allocations[0]->item, 'Le lot le plus proche de la péremption doit partir en premier.');
        self::assertSame(1.0, $plan->allocations[0]->quantity);
        self::assertSame($late, $plan->allocations[1]->item);
        self::assertSame(0.5, $plan->allocations[1]->quantity);
    }

    public function testReportsShortfallInsteadOfOverdrawing(): void
    {
        $variant = $this->variant('riz', Unit::Gram);

        $plan = $this->allocator([$this->lot($variant, 200, Unit::Gram)])
            ->plan($variant, 500, Unit::Gram);

        self::assertFalse($plan->isComplete());
        self::assertSame(200.0, $plan->allocated);
        self::assertSame(300.0, $plan->shortfall);
    }

    public function testConvertsBetweenCompatibleUnits(): void
    {
        // Demande en grammes, lot stocké en kilogrammes.
        $variant = $this->variant('farine', Unit::Kilogram);

        $plan = $this->allocator([$this->lot($variant, 2, Unit::Kilogram)])
            ->plan($variant, 500, Unit::Gram);

        self::assertTrue($plan->isComplete());
        self::assertSame(0.5, $plan->allocations[0]->quantity);
        self::assertSame(Unit::Kilogram, $plan->allocations[0]->unit);
    }

    public function testStopsOnceTheRequestIsSatisfied(): void
    {
        $variant = $this->variant('riz', Unit::Gram);
        $first = $this->lot($variant, 1000, Unit::Gram);
        $second = $this->lot($variant, 1000, Unit::Gram);

        $plan = $this->allocator([$first, $second])->plan($variant, 400, Unit::Gram);

        self::assertCount(1, $plan->allocations, 'Le second lot ne doit pas être entamé inutilement.');
        self::assertSame($first, $plan->allocations[0]->item);
    }

    /**
     * Le défaut corrigé par les variantes : des tomates en conserve ne servent
     * jamais une demande de tomates fraîches, même si la clé d'ingrédient est
     * la même.
     */
    public function testFreshAndCannedAreSeparateStocks(): void
    {
        $fresh = $this->variant('tomate', Unit::Gram);
        $canned = $this->variant('tomate', Unit::Gram, [ProductTrait::Canned]);

        self::assertNotSame(
            $fresh->getIdentityHash(),
            $canned->getIdentityHash(),
            'Deux états d\'un même aliment ne partagent pas la même identité.',
        );
        self::assertSame('tomate', $fresh->getSlug());
        self::assertSame('tomate-conserve', $canned->getSlug());

        // Seuls les lots de la variante demandée sont proposés à l'allocation.
        $plan = $this->allocator([$this->lot($canned, 400, Unit::Gram)])
            ->plan($canned, 400, Unit::Gram);

        self::assertTrue($plan->isComplete());
        self::assertSame($canned, $plan->variant);
    }

    /** Une quantité estimée doit être signalée : elle ne se déduit pas au gramme près. */
    public function testFlagsPlansDrawingOnEstimatedQuantities(): void
    {
        $variant = $this->variant('riz', Unit::Gram);
        $lot = $this->lot($variant, 500, Unit::Gram);
        $lot->setQuantityIsEstimated(true);

        $plan = $this->allocator([$lot])->plan($variant, 200, Unit::Gram);

        self::assertTrue($plan->drawsOnEstimatedQuantity());
    }

    /** @param list<StockItem> $lots */
    private function allocator(array $lots): FefoAllocator
    {
        $repository = $this->createMock(StockItemRepository::class);
        $repository->method('findAvailableByVariant')->willReturn($lots);

        return new FefoAllocator($repository, new UnitConverter());
    }

    /** @param list<ProductTrait> $traits */
    private function variant(string $key, Unit $unit, array $traits = []): HouseholdProductVariant
    {
        $identity = VariantIdentity::of($key, $unit->family(), $traits);

        return new HouseholdProductVariant($this->household, $key, $identity, $identity->slugBase());
    }

    private function lot(
        HouseholdProductVariant $variant,
        float $quantity,
        Unit $unit,
        ?string $expiresIn = null,
    ): StockItem {
        $item = new StockItem($this->household, $variant, $quantity, $unit, StorageLocation::Pantry);

        if (null !== $expiresIn) {
            $item->setExpirationDate(new \DateTimeImmutable($expiresIn));
        }

        return $item;
    }
}
