<?php

declare(strict_types=1);

namespace App\Tests\Service\GroceryReturn;

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
            $this->decide(nameKnown: false, unitMismatch: true, freezerCandidate: true, storage: StorageLocation::Fridge),
        );
    }

    public function testAUnitFromAnotherFamilyIsAQuestionNotACorrection(): void
    {
        self::assertSame(GroceryReturnDecision::UnitMismatch, $this->decide(unitMismatch: true));
    }

    /** Meat with no habit and no instruction: the freezer stops the clock, so ask. */
    public function testMeatHeadedToTheFridgeByDefaultAsksAboutTheFreezer(): void
    {
        self::assertSame(
            GroceryReturnDecision::FridgeOrFreezer,
            $this->decide(freezerCandidate: true, storage: StorageLocation::Fridge),
        );
    }

    /** A habit is an answer: the household already keeps this food somewhere. */
    public function testMeatWithAHabitDoesNotAsk(): void
    {
        self::assertSame(
            GroceryReturnDecision::None,
            $this->decide(freezerCandidate: true, storage: StorageLocation::Fridge, storageProvenance: GroceryReturnDecider::STORAGE_HABIT),
        );
        self::assertSame(
            GroceryReturnDecision::None,
            $this->decide(freezerCandidate: true, storage: StorageLocation::Fridge, storageProvenance: GroceryReturnDecider::STORAGE_GIVEN),
        );
    }

    /** A "surgelé" label already sent the food to the freezer: nothing to ask. */
    public function testMeatAlreadyInTheFreezerDoesNotAsk(): void
    {
        self::assertSame(
            GroceryReturnDecision::None,
            $this->decide(freezerCandidate: true, storage: StorageLocation::Freezer),
        );
    }

    /** A chilled package is never asked for its printed date: the estimate is shown instead. */
    public function testAChilledPackageIsNotAskedForItsDate(): void
    {
        self::assertSame(GroceryReturnDecision::None, $this->decide(storage: StorageLocation::Fridge));
    }

    private function decide(
        bool $nameKnown = true,
        bool $unitMismatch = false,
        StorageLocation $storage = StorageLocation::Pantry,
        string $storageProvenance = GroceryReturnDecider::STORAGE_DEFAULT,
        bool $freezerCandidate = false,
    ): GroceryReturnDecision {
        return $this->decider->decide(
            nameKnown: $nameKnown,
            unitMismatch: $unitMismatch,
            storage: $storage,
            storageProvenance: $storageProvenance,
            freezerCandidate: $freezerCandidate,
        );
    }
}
