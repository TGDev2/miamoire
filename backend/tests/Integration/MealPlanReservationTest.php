<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Household;
use App\Enum\MealPlanLineStatus;
use App\Enum\MealSlot;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Service\MealPlan\MealPlanService;

/**
 * L'algorithme de réservation souple : jamais une déduction de stock, mais ce
 * qui reste disponible une fois les repas plus proches déjà servis.
 */
class MealPlanReservationTest extends DatabaseTestCase
{
    private MealPlanService $mealPlan;
    private Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var MealPlanService $mealPlan */
        $mealPlan = static::getContainer()->get(MealPlanService::class);
        $this->mealPlan = $mealPlan;

        $this->household = $this->makeHousehold();
    }

    /**
     * Le cas central : deux entrées réclament le même ingrédient, un manque
     * cumulé ne doit produire qu'UNE seule ligne agrégée — pas une par entrée,
     * sous peine que `ShoppingListService::addMany()` (qui dédoublonne par
     * variante sans cumuler) fasse silencieusement disparaître la seconde.
     */
    public function testCumulativeShortfallAcrossTwoEntriesIsAggregatedIntoOneLine(): void
    {
        $flour = $this->makeVariant($this->household, 'Farine', Unit::Gram);
        $this->makeLot($this->household, $flour, 100, Unit::Gram);

        $this->makeMealPlanEntry($this->household, new \DateTimeImmutable('+1 day'), MealSlot::Lunch, [
            ['key' => $flour->getSlug(), 'label' => 'Farine', 'quantity' => 80, 'unit' => Unit::Gram],
        ]);
        $this->makeMealPlanEntry($this->household, new \DateTimeImmutable('+2 days'), MealSlot::Lunch, [
            ['key' => $flour->getSlug(), 'label' => 'Farine', 'quantity' => 80, 'unit' => Unit::Gram],
        ]);
        $this->entityManager->flush();

        $overview = $this->mealPlan->overview($this->household);

        self::assertCount(1, $overview->shortfallSummary, 'Un manque partagé par deux repas ne doit produire qu\'une seule ligne.');
        self::assertSame('Farine', $overview->shortfallSummary[0]->label);
        // 100 g disponibles, 160 g demandés au total : 60 g manquent.
        self::assertEqualsWithDelta(60.0, $overview->shortfallSummary[0]->quantity, 0.01);
    }

    public function testSecondEntrySeesAvailabilityReducedByTheFirst(): void
    {
        $milk = $this->makeVariant($this->household, 'Lait', Unit::Milliliter);
        $this->makeLot($this->household, $milk, 100, Unit::Milliliter);

        $this->makeMealPlanEntry($this->household, new \DateTimeImmutable('+1 day'), MealSlot::Lunch, [
            ['key' => $milk->getSlug(), 'label' => 'Lait', 'quantity' => 60, 'unit' => Unit::Milliliter],
        ]);
        $this->makeMealPlanEntry($this->household, new \DateTimeImmutable('+1 day'), MealSlot::Dinner, [
            ['key' => $milk->getSlug(), 'label' => 'Lait', 'quantity' => 60, 'unit' => Unit::Milliliter],
        ]);
        $this->entityManager->flush();

        $overview = $this->mealPlan->overview($this->household);

        self::assertCount(2, $overview->entries);
        self::assertSame(MealPlanLineStatus::Covered, $overview->entries[0]->lines[0]->status);
        self::assertSame(MealPlanLineStatus::Short, $overview->entries[1]->lines[0]->status);
        self::assertEqualsWithDelta(20.0, $overview->entries[1]->lines[0]->shortfall, 0.01);
    }

    public function testEntriesAreOrderedByDateThenSlot(): void
    {
        // Créées volontairement dans le désordre : dîner avant déjeuner, jour
        // suivant avant le lendemain — le tri doit tout remettre en ordre.
        $this->makeMealPlanEntry($this->household, new \DateTimeImmutable('+2 days'), MealSlot::Breakfast, null, 'Repas tardif');
        $this->makeMealPlanEntry($this->household, new \DateTimeImmutable('+1 day'), MealSlot::Dinner, null, 'Dîner');
        $this->makeMealPlanEntry($this->household, new \DateTimeImmutable('+1 day'), MealSlot::Lunch, null, 'Déjeuner');
        $this->entityManager->flush();

        $overview = $this->mealPlan->overview($this->household);

        self::assertSame(
            ['Déjeuner', 'Dîner', 'Repas tardif'],
            array_map(static fn ($view): string => $view->entry->displayName(), $overview->entries),
        );
    }

    public function testUnresolvableIngredientKeyIsFlaggedWithoutCrashing(): void
    {
        $this->makeMealPlanEntry($this->household, new \DateTimeImmutable('+1 day'), MealSlot::Lunch, [
            ['key' => 'aliment-disparu', 'label' => 'Aliment disparu', 'quantity' => 100, 'unit' => Unit::Gram],
        ]);
        $this->entityManager->flush();

        $overview = $this->mealPlan->overview($this->household);

        self::assertCount(1, $overview->entries[0]->lines);
        self::assertSame(MealPlanLineStatus::NeedsReassociation, $overview->entries[0]->lines[0]->status);
    }

    public function testUnitFamilyMismatchIsHandledGracefully(): void
    {
        // « Riz » se compte en grammes : une proposition en « unité » (famille
        // dénombrable) ne s'y applique pas, comme RecipeConfirmationService le
        // vérifie déjà pour une confirmation réelle.
        $rice = $this->makeVariant($this->household, 'Riz', Unit::Gram);
        $this->makeMealPlanEntry($this->household, new \DateTimeImmutable('+1 day'), MealSlot::Lunch, [
            ['key' => $rice->getSlug(), 'label' => 'Riz', 'quantity' => 2, 'unit' => Unit::Piece],
        ]);
        $this->entityManager->flush();

        $overview = $this->mealPlan->overview($this->household);

        self::assertSame(MealPlanLineStatus::NeedsReassociation, $overview->entries[0]->lines[0]->status);
    }

    public function testAlsoClaimedByIsPopulatedEvenWithoutAnyShortage(): void
    {
        $milk = $this->makeVariant($this->household, 'Lait', Unit::Milliliter);
        $this->makeLot($this->household, $milk, 1000, Unit::Milliliter);

        $first = $this->makeMealPlanEntry($this->household, new \DateTimeImmutable('+1 day'), MealSlot::Lunch, [
            ['key' => $milk->getSlug(), 'label' => 'Lait', 'quantity' => 50, 'unit' => Unit::Milliliter],
        ]);
        $second = $this->makeMealPlanEntry($this->household, new \DateTimeImmutable('+2 days'), MealSlot::Lunch, [
            ['key' => $milk->getSlug(), 'label' => 'Lait', 'quantity' => 50, 'unit' => Unit::Milliliter],
        ]);
        $this->entityManager->flush();

        $overview = $this->mealPlan->overview($this->household);

        self::assertSame(MealPlanLineStatus::Covered, $overview->entries[0]->lines[0]->status);
        self::assertCount(1, $overview->entries[0]->lines[0]->alsoClaimedBy);
        self::assertSame((string) $second->getId(), $overview->entries[0]->lines[0]->alsoClaimedBy[0]->entryId);
        self::assertCount(1, $overview->entries[1]->lines[0]->alsoClaimedBy);
        self::assertSame((string) $first->getId(), $overview->entries[1]->lines[0]->alsoClaimedBy[0]->entryId);
    }

    public function testFreeformEntryHasNoLinesAndNoPlan(): void
    {
        $this->makeMealPlanEntry($this->household, new \DateTimeImmutable('+1 day'), MealSlot::Dinner, null, 'Resto ce soir');
        $this->entityManager->flush();

        $overview = $this->mealPlan->overview($this->household);

        self::assertSame([], $overview->entries[0]->lines);
        self::assertNull($overview->entries[0]->plan);
    }

    public function testUnclaimedUrgentItemsExcludeVariantsAlreadyInThePlan(): void
    {
        $milk = $this->makeVariant($this->household, 'Lait', Unit::Milliliter);
        $milkLot = $this->makeLot($this->household, $milk, 200, Unit::Milliliter);
        $milkLot->setStorageLocation(StorageLocation::Fridge);
        $milkLot->openNextPackage();

        $yogurt = $this->makeVariant($this->household, 'Yaourt', Unit::Piece);
        $yogurtLot = $this->makeLot($this->household, $yogurt, 4, Unit::Piece);
        $yogurtLot->setStorageLocation(StorageLocation::Fridge);
        $yogurtLot->openNextPackage();

        $this->makeMealPlanEntry($this->household, new \DateTimeImmutable('+1 day'), MealSlot::Lunch, [
            ['key' => $milk->getSlug(), 'label' => 'Lait', 'quantity' => 50, 'unit' => Unit::Milliliter],
        ]);
        $this->entityManager->flush();

        $overview = $this->mealPlan->overview($this->household);

        $unclaimedVariantIds = array_map(
            static fn ($candidate): string => (string) $candidate->item->getVariant()->getId(),
            $overview->unclaimedUrgentItems,
        );

        self::assertNotContains((string) $milk->getId(), $unclaimedVariantIds);
        self::assertContains((string) $yogurt->getId(), $unclaimedVariantIds);
    }
}
