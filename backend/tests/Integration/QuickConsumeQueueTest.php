<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\StockItem;
use App\Entity\StockOperation;
use App\Enum\MovementType;
use App\Enum\QuickConsumeReason;
use App\Enum\StockOperationType;
use App\Enum\Unit;
use App\Repository\StockItemRepository;
use App\Service\Inventory\QuickConsumeCandidate;
use App\Service\Inventory\QuickConsumeSelector;
use Symfony\Component\Uid\Uuid;

/**
 * La sélection de « J'ai utilisé quelque chose », vérifiée à travers le vrai
 * repository. Comme {@see StockReviewQueueTest}, `$now` est injecté en avance
 * dans le futur pour simuler l'écoulement du temps plutôt que d'attendre
 * réellement des jours entiers.
 */
class QuickConsumeQueueTest extends DatabaseTestCase
{
    private QuickConsumeSelector $selector;
    private Household $household;
    private HouseholdProductVariant $riz;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var QuickConsumeSelector $selector */
        $selector = static::getContainer()->get(QuickConsumeSelector::class);
        $this->selector = $selector;

        $this->household = $this->makeHousehold();
        $this->riz = $this->makeVariant($this->household, 'Riz', Unit::Gram);
    }

    public function testOpenedItemIsFlagged(): void
    {
        $item = $this->makeLot($this->household, $this->riz, 500, Unit::Gram);
        $item->openNextPackage();
        $this->entityManager->flush();

        self::assertContains(QuickConsumeReason::Opened, $this->reasonsFor($item->getId()));
    }

    /** Le lot consommé est désormais vide (donc plus « disponible ») : le signal doit remonter sur l'autre lot du même produit. */
    public function testRecentlyConsumedVariantIsFlaggedOnItsCurrentAvailableLot(): void
    {
        $emptied = $this->makeLot($this->household, $this->riz, 500, Unit::Gram);
        $emptied->withdraw(500);
        $this->makeMovement($emptied, MovementType::Consumption);

        $available = $this->makeLot($this->household, $this->riz, 300, Unit::Gram);
        $this->entityManager->flush();

        self::assertContains(QuickConsumeReason::RecentlyConsumed, $this->reasonsFor($available->getId()));
    }

    public function testRecentlyUsedInRecipeIsDistinguishedFromPlainConsumption(): void
    {
        $operation = new StockOperation($this->household, 'op-1', StockOperationType::RecipeConfirmation, 'hash');
        $this->entityManager->persist($operation);

        $item = $this->makeLot($this->household, $this->riz, 500, Unit::Gram);
        $this->makeMovement($item, MovementType::Consumption, $operation);
        $this->entityManager->flush();

        $reasons = $this->reasonsFor($item->getId());

        self::assertContains(QuickConsumeReason::RecentlyUsedInRecipe, $reasons);
        self::assertNotContains(QuickConsumeReason::RecentlyConsumed, $reasons);
    }

    public function testOldConsumptionFallsOutsideTheLookbackWindow(): void
    {
        $item = $this->makeLot($this->household, $this->riz, 500, Unit::Gram);
        $this->makeMovement($item, MovementType::Consumption);
        $this->entityManager->flush();

        $candidates = $this->selector->selectForQuickConsume($this->household, 10, new \DateTimeImmutable('+20 days'));

        self::assertNotContains(
            (string) $item->getId(),
            array_map(static fn (QuickConsumeCandidate $c): string => (string) $c->item->getId(), $candidates),
        );
    }

    public function testRecentlyViewedItemIsFlagged(): void
    {
        $item = $this->makeLot($this->household, $this->riz, 500, Unit::Gram);
        $this->entityManager->flush();

        $this->markViewed($item);

        self::assertContains(QuickConsumeReason::RecentlyViewed, $this->reasonsFor($item->getId()));
    }

    public function testOldViewFallsOutsideItsShorterWindow(): void
    {
        $item = $this->makeLot($this->household, $this->riz, 500, Unit::Gram);
        $this->entityManager->flush();

        $this->markViewed($item);

        $candidates = $this->selector->selectForQuickConsume($this->household, 10, new \DateTimeImmutable('+4 days'));

        self::assertNotContains(
            (string) $item->getId(),
            array_map(static fn (QuickConsumeCandidate $c): string => (string) $c->item->getId(), $candidates),
        );
    }

    public function testTwoLotsOfTheSameVariantProduceOnlyOneCandidate(): void
    {
        $item1 = $this->makeLot($this->household, $this->riz, 500, Unit::Gram);
        $item1->openNextPackage();
        $item2 = $this->makeLot($this->household, $this->riz, 300, Unit::Gram);
        $item2->openNextPackage();
        $this->entityManager->flush();

        $candidates = $this->selector->selectForQuickConsume($this->household, 10);

        $matching = array_filter(
            $candidates,
            fn (QuickConsumeCandidate $c): bool => (string) $c->item->getVariant()->getId() === (string) $this->riz->getId(),
        );

        self::assertCount(1, $matching);
    }

    public function testResultIsHardCappedAtTenRegardlessOfRequestedLimit(): void
    {
        for ($i = 0; $i < 12; ++$i) {
            $variant = $this->makeVariant($this->household, sprintf('Produit %d', $i), Unit::Gram);
            $item = $this->makeLot($this->household, $variant, 500, Unit::Gram);
            $item->openNextPackage();
        }
        $this->entityManager->flush();

        $candidates = $this->selector->selectForQuickConsume($this->household, 100);

        self::assertCount(10, $candidates);
    }

    public function testItemWithNoReasonIsExcludedEntirely(): void
    {
        $item = $this->makeLot($this->household, $this->riz, 500, Unit::Gram);
        $this->entityManager->flush();

        $candidates = $this->selector->selectForQuickConsume($this->household);

        self::assertNotContains(
            (string) $item->getId(),
            array_map(static fn (QuickConsumeCandidate $c): string => (string) $c->item->getId(), $candidates),
        );
    }

    private function markViewed(StockItem $item): void
    {
        /** @var StockItemRepository $stockItems */
        $stockItems = static::getContainer()->get(StockItemRepository::class);
        $stockItems->markViewed($item->getId(), $this->household, new \DateTimeImmutable());
        $this->entityManager->refresh($item);
    }

    /** @return list<QuickConsumeReason> */
    private function reasonsFor(Uuid $itemId): array
    {
        $candidates = $this->selector->selectForQuickConsume($this->household, 10);

        foreach ($candidates as $candidate) {
            if ((string) $candidate->item->getId() === (string) $itemId) {
                return $candidate->reasons;
            }
        }

        self::fail("Le lot attendu n'est pas apparu dans la sélection.");
    }
}
