<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Enum\UrgencyReason;
use App\Enum\Unit;
use App\Service\Inventory\SaveTodayCandidate;
use App\Service\Inventory\SaveTodaySelector;

/**
 * La sélection de « Aujourd'hui », vérifiée à travers le vrai repository :
 * l'ordre FEFO déjà testé ailleurs (`OpenedLotPriorityTest`) n'est pas
 * réévalué ici, seul le filtre (motif d'urgence, report) l'est.
 */
class SaveTodayQueueTest extends DatabaseTestCase
{
    private SaveTodaySelector $selector;
    private Household $household;
    private HouseholdProductVariant $riz;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var SaveTodaySelector $selector */
        $selector = static::getContainer()->get(SaveTodaySelector::class);
        $this->selector = $selector;

        $this->household = $this->makeHousehold();
        // Sans date, jamais ouvert : le témoin négatif de « aucun motif ».
        $this->riz = $this->makeVariant($this->household, 'Riz', Unit::Gram);
    }

    public function testExpiredItemIsFlaggedExpired(): void
    {
        $item = $this->makeLot($this->household, $this->riz, 500, Unit::Gram, '-2 days');
        $this->entityManager->flush();

        $reasons = $this->reasonsFor($item->getId());

        self::assertContains(UrgencyReason::Expired, $reasons);
        self::assertNotContains(UrgencyReason::ExpiringSoon, $reasons);
    }

    public function testItemExpiringSoonIsFlagged(): void
    {
        $item = $this->makeLot($this->household, $this->riz, 500, Unit::Gram, '+2 days');
        $this->entityManager->flush();

        $reasons = $this->reasonsFor($item->getId());

        self::assertContains(UrgencyReason::ExpiringSoon, $reasons);
    }

    public function testOpenedItemIsFlagged(): void
    {
        $item = $this->makeLot($this->household, $this->riz, 500, Unit::Gram);
        $item->openNextPackage();
        $this->entityManager->flush();

        $reasons = $this->reasonsFor($item->getId());

        self::assertContains(UrgencyReason::Opened, $reasons);
    }

    /**
     * Congelé, un exemplaire entamé n'est plus une urgence : la congélation
     * suspend justement ça. Sans autre motif, le lot ne doit même plus
     * apparaître dans la sélection.
     */
    public function testOpenedItemInTheFreezerIsNotFlagged(): void
    {
        $item = $this->makeLot($this->household, $this->riz, 500, Unit::Gram);
        $item->openNextPackage();
        $item->setStorageLocation(\App\Enum\StorageLocation::Freezer);
        $this->entityManager->flush();

        $candidates = $this->selector->selectForToday($this->household);

        self::assertNotContains(
            (string) $item->getId(),
            array_map(static fn (SaveTodayCandidate $candidate): string => (string) $candidate->item->getId(), $candidates),
        );
    }

    /** Le même geste, mais avec une échéance proche encore active : le lot reste visible, sans le motif « Entamé ». */
    public function testOpenedItemInTheFreezerKeepsOtherReasons(): void
    {
        $item = $this->makeLot($this->household, $this->riz, 500, Unit::Gram, '+2 days');
        $item->openNextPackage();
        $item->setStorageLocation(\App\Enum\StorageLocation::Freezer);
        $this->entityManager->flush();

        $reasons = $this->reasonsFor($item->getId());

        self::assertContains(UrgencyReason::ExpiringSoon, $reasons);
        self::assertNotContains(UrgencyReason::Opened, $reasons);
    }

    public function testItemWithNoUrgencyIsExcluded(): void
    {
        $item = $this->makeLot($this->household, $this->riz, 500, Unit::Gram);
        $this->entityManager->flush();

        $candidates = $this->selector->selectForToday($this->household);

        self::assertNotContains(
            (string) $item->getId(),
            array_map(static fn (SaveTodayCandidate $candidate): string => (string) $candidate->item->getId(), $candidates),
        );
    }

    public function testASnoozedItemIsExcludedWhileItsRemindAfterIsInTheFuture(): void
    {
        $item = $this->makeLot($this->household, $this->riz, 500, Unit::Gram, '+1 day');
        $item->setRemindAfter(new \DateTimeImmutable('+1 day'));
        $this->entityManager->flush();

        $candidates = $this->selector->selectForToday($this->household);

        self::assertNotContains(
            (string) $item->getId(),
            array_map(static fn (SaveTodayCandidate $candidate): string => (string) $candidate->item->getId(), $candidates),
        );
    }

    /** Un report passé n'écarte plus rien : le lot redevient un candidat normal. */
    public function testAnItemReappearsOnceItsRemindAfterHasPassed(): void
    {
        $item = $this->makeLot($this->household, $this->riz, 500, Unit::Gram, '+1 day');
        $item->setRemindAfter(new \DateTimeImmutable('-1 hour'));
        $this->entityManager->flush();

        $reasons = $this->reasonsFor($item->getId());

        self::assertContains(UrgencyReason::ExpiringSoon, $reasons);
    }

    /** L'échéance la plus proche sort en tête, exactement comme la consommation FEFO. */
    public function testResultsAreOrderedByEffectiveDeadline(): void
    {
        $later = $this->makeLot($this->household, $this->riz, 500, Unit::Gram, '+2 days');
        $sooner = $this->makeLot($this->household, $this->riz, 500, Unit::Gram, '+1 day');
        $this->entityManager->flush();

        $candidates = $this->selector->selectForToday($this->household);

        self::assertSame((string) $sooner->getId(), (string) $candidates[0]->item->getId());
        self::assertSame((string) $later->getId(), (string) $candidates[1]->item->getId());
    }

    public function testResultIsHardCappedAtFiveRegardlessOfRequestedLimit(): void
    {
        for ($i = 0; $i < 7; ++$i) {
            $variant = $this->makeVariant($this->household, sprintf('Produit %d', $i), Unit::Gram);
            $this->makeLot($this->household, $variant, 500, Unit::Gram, '+1 day');
        }
        $this->entityManager->flush();

        $candidates = $this->selector->selectForToday($this->household, 100);

        self::assertCount(5, $candidates);
    }

    /** @return list<UrgencyReason> */
    private function reasonsFor(\Symfony\Component\Uid\Uuid $itemId): array
    {
        $candidates = $this->selector->selectForToday($this->household);

        foreach ($candidates as $candidate) {
            if ((string) $candidate->item->getId() === (string) $itemId) {
                return $candidate->reasons;
            }
        }

        self::fail('Le lot attendu n\'est pas apparu dans la sélection « Aujourd\'hui ».');
    }
}
