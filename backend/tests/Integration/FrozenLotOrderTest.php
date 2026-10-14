<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Repository\StockItemRepository;

/**
 * First expired, first out — and a frozen lot does not expire, it waits.
 *
 * Ordered by date alone, a frozen lot past its quality date came before a
 * fridge lot due in two days: a recipe would have taken the chicken from the
 * freezer and left the fridge one to spoil, and the stock list put "best
 * eaten soon" above "expired". Frozen lots now come after every other lot,
 * ordered among themselves by their quality date.
 */
class FrozenLotOrderTest extends DatabaseTestCase
{
    public function testAFrozenLotComesAfterTheLotsThatCanSpoil(): void
    {
        $household = $this->makeHousehold();
        $chicken = $this->makeVariant($household, 'Blanc de poulet', Unit::Gram);

        $frozen = $this->makeLot($household, $chicken, 500, Unit::Gram, '-200 days');
        $frozen->setStorageLocation(StorageLocation::Freezer);
        $frozen->setPurchaseDate(new \DateTimeImmutable('-300 days'));
        $frozenLater = $this->makeLot($household, $chicken, 500, Unit::Gram, '+400 days');
        $frozenLater->setStorageLocation(StorageLocation::Freezer);
        $fridge = $this->makeLot($household, $chicken, 500, Unit::Gram, '+2 days');
        $fridge->setStorageLocation(StorageLocation::Fridge);
        $undated = $this->makeLot($household, $chicken, 500, Unit::Gram);
        $this->entityManager->flush();

        /** @var StockItemRepository $repository */
        $repository = static::getContainer()->get(StockItemRepository::class);
        $order = array_map(
            static fn ($item): string => (string) $item->getId(),
            $repository->findAvailableByVariant($chicken),
        );

        self::assertSame(
            [(string) $fridge->getId(), (string) $undated->getId(), (string) $frozen->getId(), (string) $frozenLater->getId()],
            $order,
        );
    }
}
