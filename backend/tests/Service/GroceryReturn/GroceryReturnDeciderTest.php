<?php

declare(strict_types=1);

namespace App\Tests\Service\GroceryReturn;

use App\Enum\FoodNature;
use App\Enum\GroceryReturnDecision;
use App\Enum\StorageLocation;
use App\Service\GroceryReturn\GroceryReturnDecider;
use PHPUnit\Framework\TestCase;

/**
 * A line asks at most one question, and only when the answer changes what
 * is written. Each rule is pinned here on its own, then their order.
 */
class GroceryReturnDeciderTest extends TestCase
{
    private GroceryReturnDecider $decider;

    protected function setUp(): void
    {
        $this->decider = new GroceryReturnDecider();
    }

    public function testNothingToAskForAnOrdinaryPackagedFoodInThePantry(): void
    {
        self::assertSame(GroceryReturnDecision::None, $this->decide());
    }

    public function testAnUnknownFoodComesBeforeEverythingElse(): void
    {
        self::assertSame(
            GroceryReturnDecision::UnknownFood,
            $this->decide(nameKnown: false, unitMismatch: true, isProtein: true, storage: StorageLocation::Fridge),
        );
    }

    public function testAUnitFromAnotherFamilyIsAQuestionNotACorrection(): void
    {
        self::assertSame(GroceryReturnDecision::UnitMismatch, $this->decide(unitMismatch: true));
    }

    /** Meat with no habit and no instruction: the freezer stops the clock, so ask. */
    public function testAProteinHeadedToTheFridgeByDefaultAsksAboutTheFreezer(): void
    {
        self::assertSame(
            GroceryReturnDecision::FridgeOrFreezer,
            $this->decide(isProtein: true, storage: StorageLocation::Fridge),
        );
    }

    /** A habit is an answer: the household already keeps this food somewhere. */
    public function testAProteinWithAHabitDoesNotAsk(): void
    {
        self::assertSame(
            GroceryReturnDecision::None,
            $this->decide(isProtein: true, storage: StorageLocation::Fridge, storageProvenance: GroceryReturnDecider::STORAGE_HABIT),
        );
        self::assertSame(
            GroceryReturnDecision::None,
            $this->decide(isProtein: true, storage: StorageLocation::Fridge, storageProvenance: GroceryReturnDecider::STORAGE_GIVEN),
        );
    }

    public function testAProteinAlreadyInTheFreezerDoesNotAsk(): void
    {
        self::assertSame(
            GroceryReturnDecision::None,
            $this->decide(isProtein: true, storage: StorageLocation::Freezer, estimatedDays: 180),
        );
    }

    /** A chilled package with a short estimate has a printed date worth reading. */
    public function testAShortEstimateOnAChilledPackageAsksForThePrintedDate(): void
    {
        self::assertSame(
            GroceryReturnDecision::ShortDate,
            $this->decide(storage: StorageLocation::Fridge, estimatedDays: GroceryReturnDecider::SHORT_DATE_DAYS),
        );
    }

    public function testALongerEstimateDoesNotAsk(): void
    {
        self::assertSame(
            GroceryReturnDecision::None,
            $this->decide(storage: StorageLocation::Fridge, estimatedDays: GroceryReturnDecider::SHORT_DATE_DAYS + 1),
        );
    }

    /** Fresh produce has no printed date to read: its short estimate is all there is. */
    public function testFreshProduceIsNeverAskedForAPrintedDate(): void
    {
        self::assertSame(
            GroceryReturnDecision::None,
            $this->decide(nature: FoodNature::Fresh, storage: StorageLocation::Fridge, estimatedDays: 3),
        );
    }

    public function testThePantryHasEnoughSlackNotToAsk(): void
    {
        self::assertSame(GroceryReturnDecision::None, $this->decide(storage: StorageLocation::Pantry, estimatedDays: 3));
    }

    /** A printed date or a cleared date leaves no estimate, hence nothing to ask. */
    public function testWithoutAnEstimateThereIsNoDateQuestion(): void
    {
        self::assertSame(GroceryReturnDecision::None, $this->decide(storage: StorageLocation::Fridge, estimatedDays: null));
    }

    /** Where it goes decides what the date means: the freezer question comes first. */
    public function testTheFreezerQuestionComesBeforeTheDateQuestion(): void
    {
        self::assertSame(
            GroceryReturnDecision::FridgeOrFreezer,
            $this->decide(isProtein: true, storage: StorageLocation::Fridge, estimatedDays: 3),
        );
    }

    private function decide(
        bool $nameKnown = true,
        bool $unitMismatch = false,
        FoodNature $nature = FoodNature::Packaged,
        StorageLocation $storage = StorageLocation::Pantry,
        string $storageProvenance = GroceryReturnDecider::STORAGE_DEFAULT,
        bool $isProtein = false,
        ?int $estimatedDays = 30,
    ): GroceryReturnDecision {
        return $this->decider->decide(
            nameKnown: $nameKnown,
            unitMismatch: $unitMismatch,
            nature: $nature,
            storage: $storage,
            storageProvenance: $storageProvenance,
            isProtein: $isProtein,
            estimatedDays: $estimatedDays,
        );
    }
}
