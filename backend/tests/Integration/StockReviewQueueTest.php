<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Enum\ReviewReason;
use App\Enum\Unit;
use App\Service\Inventory\ReviewCandidate;
use App\Service\Inventory\StockReviewSelector;

/**
 * La sélection de « Vérifier mon stock », vérifiée à travers le vrai
 * repository : `createdAt`/`updatedAt` ne sont modifiables par aucune API
 * publique, donc `$now` est injecté en avance dans le futur plutôt que
 * d'attendre réellement des jours entiers (voir la doc de
 * {@see StockReviewSelector::selectForReview()}).
 */
class StockReviewQueueTest extends DatabaseTestCase
{
    private StockReviewSelector $selector;
    private Household $household;
    private HouseholdProductVariant $riz;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var StockReviewSelector $selector */
        $selector = static::getContainer()->get(StockReviewSelector::class);
        $this->selector = $selector;

        $this->household = $this->makeHousehold();
        // Conservation très longue (365 j au garde-manger) : jamais « stale »,
        // c'est le témoin négatif de plusieurs cas ci-dessous.
        $this->riz = $this->makeVariant($this->household, 'Riz', Unit::Gram);
    }

    public function testOpenedItemIsFlagged(): void
    {
        $item = $this->makeLot($this->household, $this->riz, 500, Unit::Gram);
        $item->openNextPackage();
        $this->entityManager->flush();

        $reasons = $this->reasonsFor($item->getId());

        self::assertContains(ReviewReason::Opened, $reasons);
    }

    public function testQuantityEstimatedItemIsFlagged(): void
    {
        $item = $this->makeLot($this->household, $this->riz, 500, Unit::Gram);
        $item->setQuantityIsEstimated(true);
        $this->entityManager->flush();

        $reasons = $this->reasonsFor($item->getId());

        self::assertContains(ReviewReason::QuantityEstimated, $reasons);
    }

    public function testExpiredItemIsFlaggedExpiredNotExpiringSoon(): void
    {
        $item = $this->makeLot($this->household, $this->riz, 500, Unit::Gram, '-2 days');
        $this->entityManager->flush();

        $reasons = $this->reasonsFor($item->getId());

        self::assertContains(ReviewReason::Expired, $reasons);
        self::assertNotContains(ReviewReason::ExpiringSoon, $reasons);
    }

    public function testItemExpiringSoonIsFlagged(): void
    {
        $item = $this->makeLot($this->household, $this->riz, 500, Unit::Gram, '+2 days');
        $this->entityManager->flush();

        $reasons = $this->reasonsFor($item->getId());

        self::assertContains(ReviewReason::ExpiringSoon, $reasons);
    }

    /** La salade se garde un jour au garde-manger : deux jours plus tard, elle est « stale ». */
    public function testUntouchedPerishableItemBecomesStale(): void
    {
        $salade = $this->makeVariant($this->household, 'Salade', Unit::Gram);
        $item = $this->makeLot($this->household, $salade, 200, Unit::Gram);
        $this->entityManager->flush();

        $reasons = $this->reasonsFor($item->getId(), new \DateTimeImmutable('+2 days'));

        self::assertContains(ReviewReason::Stale, $reasons);
    }

    /**
     * Le riz (365 j) ne devient jamais « stale » à cette échelle de temps.
     *
     * Une quantité estimée fait par ailleurs apparaître ce lot dans la file :
     * sans motif du tout, il en serait exclu et `reasonsFor()` ne pourrait
     * pas l'y trouver pour vérifier l'absence spécifique de `Stale`.
     */
    public function testLongLastingItemNeverBecomesStale(): void
    {
        $item = $this->makeLot($this->household, $this->riz, 500, Unit::Gram);
        $item->setQuantityIsEstimated(true);
        $this->entityManager->flush();

        $reasons = $this->reasonsFor($item->getId(), new \DateTimeImmutable('+40 days'));

        self::assertNotContains(ReviewReason::Stale, $reasons);
    }

    /**
     * "Stale" measures how long a lot usually waits, not its quality. In the
     * freezer every food waits for months: sausages frozen for 81 days are
     * past their 60 days of best quality, but nothing suggests they are gone.
     */
    public function testAFrozenFoodIsNotStaleBeforeTheFreezerDefault(): void
    {
        $sausages = $this->makeVariant($this->household, 'Saucisses de Toulouse', Unit::Gram);
        $item = $this->makeLot($this->household, $sausages, 500, Unit::Gram);
        $item->setStorageLocation(\App\Enum\StorageLocation::Freezer);
        $item->setQuantityIsEstimated(true);
        $this->entityManager->flush();

        self::assertNotContains(ReviewReason::Stale, $this->reasonsFor($item->getId(), new \DateTimeImmutable('+81 days')));
        self::assertContains(ReviewReason::Stale, $this->reasonsFor($item->getId(), new \DateTimeImmutable('+181 days')));
    }

    public function testItemWithNoReasonIsExcludedEntirely(): void
    {
        $item = $this->makeLot($this->household, $this->riz, 500, Unit::Gram);
        $this->entityManager->flush();

        $candidates = $this->selector->selectForReview($this->household, 10, new \DateTimeImmutable('+2 days'));

        self::assertNotContains(
            (string) $item->getId(),
            array_map(static fn (ReviewCandidate $candidate): string => (string) $candidate->item->getId(), $candidates),
        );
    }

    /** Un « Oui » (ou toute autre correction) vient de faire avancer `updatedAt` : pas de réapparition immédiate. */
    public function testRecentlyTouchedItemIsExcludedRegardlessOfReason(): void
    {
        $item = $this->makeLot($this->household, $this->riz, 500, Unit::Gram);
        $item->setQuantityIsEstimated(true);
        $this->entityManager->flush();

        // Pas de `$now` avancé : `updatedAt` vient d'être écrit, donc dans la
        // fenêtre de grâce quel que soit le motif par ailleurs applicable.
        $candidates = $this->selector->selectForReview($this->household);

        self::assertNotContains(
            (string) $item->getId(),
            array_map(static fn (ReviewCandidate $candidate): string => (string) $candidate->item->getId(), $candidates),
        );
    }

    public function testMutedCategoryExcludesItsItems(): void
    {
        $this->riz->setCategory('Épicerie');
        $item = $this->makeLot($this->household, $this->riz, 500, Unit::Gram);
        $item->setQuantityIsEstimated(true);
        $this->household->setMutedReviewCategories(['Épicerie']);
        $this->entityManager->flush();

        $candidates = $this->selector->selectForReview($this->household, 10, new \DateTimeImmutable('+2 days'));

        self::assertNotContains(
            (string) $item->getId(),
            array_map(static fn (ReviewCandidate $candidate): string => (string) $candidate->item->getId(), $candidates),
        );
    }

    public function testResultIsHardCappedAtTenRegardlessOfRequestedLimit(): void
    {
        for ($i = 0; $i < 12; ++$i) {
            $variant = $this->makeVariant($this->household, sprintf('Produit %d', $i), Unit::Gram);
            $item = $this->makeLot($this->household, $variant, 500, Unit::Gram);
            $item->setQuantityIsEstimated(true);
        }
        $this->entityManager->flush();

        $candidates = $this->selector->selectForReview($this->household, 100, new \DateTimeImmutable('+2 days'));

        self::assertCount(10, $candidates);
    }

    /**
     * `$now` par défaut à +2 jours, pas à « maintenant » : sinon la fenêtre de
     * grâce de 24 h (voir `wasRecentlyTouched`) exclurait tout lot qu'on vient
     * de créer, quel que soit le motif qu'on cherche à observer. Ce décalage
     * ne change rien aux motifs liés à la péremption (`Expired`/`ExpiringSoon`),
     * calculés par ailleurs sur la date du jour réelle.
     *
     * @return list<ReviewReason>
     */
    private function reasonsFor(\Symfony\Component\Uid\Uuid $itemId, ?\DateTimeImmutable $now = new \DateTimeImmutable('+2 days')): array
    {
        $candidates = $this->selector->selectForReview($this->household, 10, $now);

        foreach ($candidates as $candidate) {
            if ((string) $candidate->item->getId() === (string) $itemId) {
                return $candidate->reasons;
            }
        }

        self::fail('Le lot attendu n\'est pas apparu dans la file de vérification.');
    }
}
