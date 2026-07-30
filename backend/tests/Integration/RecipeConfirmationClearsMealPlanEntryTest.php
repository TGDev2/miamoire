<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\MealPlanEntry;
use App\Entity\Member;
use App\Entity\StockItem;
use App\Enum\MealSlot;
use App\Enum\Unit;
use App\Exception\StockConflictException;
use App\Service\Ai\RecipeConfirmationService;

/**
 * La réservation souple d'un repas planifié doit être remplacée par la
 * consommation réelle lors de sa préparation — jamais dupliquée, jamais
 * perdue sur un échec.
 */
class RecipeConfirmationClearsMealPlanEntryTest extends DatabaseTestCase
{
    private RecipeConfirmationService $service;
    private Household $household;
    private Member $member;
    private HouseholdProductVariant $variant;
    private StockItem $lot;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var RecipeConfirmationService $service */
        $service = static::getContainer()->get(RecipeConfirmationService::class);
        $this->service = $service;

        $this->household = $this->makeHousehold();
        $this->member = $this->makeMember($this->household);
        $this->variant = $this->makeVariant($this->household, 'Tomates', Unit::Gram);
        $this->lot = $this->makeLot($this->household, $this->variant, 500, Unit::Gram, '+3 days');

        $this->entityManager->flush();
    }

    /** @return list<array<string, mixed>> */
    private function proposal(float $quantity): array
    {
        return [[
            'key' => $this->variant->getSlug(),
            'label' => 'Tomates',
            'quantity' => $quantity,
            'unit' => 'g',
        ]];
    }

    private function makeLinkedEntry(): MealPlanEntry
    {
        $entry = $this->makeMealPlanEntry($this->household, new \DateTimeImmutable('+1 day'), MealSlot::Lunch, [
            ['key' => $this->variant->getSlug(), 'label' => 'Tomates', 'quantity' => 100, 'unit' => Unit::Gram],
        ]);
        $this->entityManager->flush();

        return $entry;
    }

    public function testSuccessfulConfirmationRemovesTheLinkedEntry(): void
    {
        $entry = $this->makeLinkedEntry();
        $entryId = $entry->getId();

        $this->service->confirm(
            $this->household,
            $this->member,
            $this->proposal(100),
            'Salade de tomates',
            'meal-plan-clear-0001',
            [],
            $entry,
        );

        self::assertNull(
            $this->entityManager->getRepository(MealPlanEntry::class)->find($entryId),
            'Une confirmation réussie doit effacer la réservation qu\'elle remplace.',
        );
    }

    public function testConfirmationWithoutALinkedEntrySucceedsNormally(): void
    {
        $result = $this->service->confirm(
            $this->household,
            $this->member,
            $this->proposal(100),
            'Salade de tomates',
            'meal-plan-clear-0002',
            [],
            null,
        );

        self::assertSame(100.0, $result['lines'][0]['applied']);
    }

    /** Un plan devenu incomplet ne doit rien effacer : la réservation survit à un échec. */
    public function testAnIncompletePlanLeavesTheLinkedEntryUntouched(): void
    {
        $entry = $this->makeLinkedEntry();
        $entryId = $entry->getId();

        try {
            $this->service->confirm(
                $this->household,
                $this->member,
                $this->proposal(10_000), // bien plus que les 500 g disponibles
                'Salade de tomates',
                'meal-plan-clear-0003',
                [],
                $entry,
            );
            self::fail('Un plan incomplet doit être refusé.');
        } catch (StockConflictException) {
            // attendu
        }

        self::assertNotNull(
            $this->entityManager->getRepository(MealPlanEntry::class)->find($entryId),
            'Un échec ne doit jamais faire disparaître la réservation.',
        );
    }

    /** Rejouer la même clé après une suppression réussie ne doit pas planter, même si l'entrée a déjà disparu. */
    public function testReplayingTheSameKeyAfterASuccessfulClearDoesNotError(): void
    {
        $entry = $this->makeLinkedEntry();

        $first = $this->service->confirm(
            $this->household,
            $this->member,
            $this->proposal(100),
            'Salade de tomates',
            'meal-plan-clear-0004',
            [],
            $entry,
        );

        // Sur un vrai rejeu, le contrôleur résoudrait l'id en `null` (l'entrée
        // a déjà disparu) — c'est exactement ce que ce second appel simule.
        $second = $this->service->confirm(
            $this->household,
            $this->member,
            $this->proposal(100),
            'Salade de tomates',
            'meal-plan-clear-0004',
            [],
            null,
        );

        self::assertFalse($first['replayed']);
        self::assertTrue($second['replayed']);
    }
}
