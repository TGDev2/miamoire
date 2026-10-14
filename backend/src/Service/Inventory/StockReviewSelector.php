<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\Household;
use App\Entity\StockItem;
use App\Enum\ReviewReason;
use App\Enum\StorageLocation;
use App\Repository\StockItemRepository;
use App\Service\Freshness\EffectiveUseByCalculator;
use App\Service\Freshness\ShelfLifeEstimator;

/**
 * Choisit les quelques lots dont la fiabilité est probablement faible, pour
 * « Vérifier mon stock ».
 *
 * Ce n'est jamais une accusation d'erreur, seulement une proposition de
 * contrôle : le plafond strict à {@see MAX_LIMIT} et la fenêtre de grâce
 * après une vérification récente sont ce qui garde la session sous la minute
 * promise et empêche qu'un lot tout juste confirmé ne réapparaisse aussitôt.
 */
class StockReviewSelector
{
    private const DEFAULT_LIMIT = 8;
    private const MAX_LIMIT = 10;

    /** En dessous, un lot vient d'être vérifié : inutile de le reproposer. */
    private const RECENTLY_TOUCHED_HOURS = 24;

    /** Bientôt périmé, au même sens que le filtre « Bientôt périmés » de l'écran stock. */
    private const EXPIRING_SOON_WITHIN_DAYS = 3;

    public function __construct(
        private readonly StockItemRepository $stockItems,
        private readonly EffectiveUseByCalculator $effectiveUseBy,
        private readonly ShelfLifeEstimator $shelfLife,
    ) {
    }

    /**
     * `$now` n'est injectable que pour les tests : simuler « il y a 40 jours »
     * sans attendre 40 jours réels, sur des lots dont `createdAt`/`updatedAt`
     * ne sont autrement modifiables par aucune API publique.
     *
     * @return list<ReviewCandidate>
     */
    public function selectForReview(Household $household, int $limit = self::DEFAULT_LIMIT, ?\DateTimeImmutable $now = null): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $muted = $household->getMutedReviewCategories();
        $now ??= new \DateTimeImmutable();

        $candidates = [];

        foreach ($this->stockItems->findAvailable($household) as $item) {
            $category = $item->getVariant()->getCategory();
            if (null !== $category && in_array($category, $muted, true)) {
                continue;
            }

            if ($this->wasRecentlyTouched($item, $now)) {
                continue;
            }

            $reasons = $this->reasonsFor($item, $now);
            if ([] === $reasons) {
                continue;
            }

            $score = array_sum(array_map(static fn (ReviewReason $reason): int => $reason->weight(), $reasons));
            $candidates[] = new ReviewCandidate($item, $reasons, $score);
        }

        usort($candidates, function (ReviewCandidate $a, ReviewCandidate $b): int {
            if ($a->score !== $b->score) {
                return $b->score <=> $a->score;
            }

            $dateA = $this->effectiveUseBy->compute($a->item)->date;
            $dateB = $this->effectiveUseBy->compute($b->item)->date;

            if ((null === $dateA) !== (null === $dateB)) {
                return null === $dateA ? 1 : -1;
            }

            if (null !== $dateA && null !== $dateB && $dateA != $dateB) {
                return $dateA <=> $dateB;
            }

            return strcmp($a->item->getVariant()->getCulinaryLabel(), $b->item->getVariant()->getCulinaryLabel());
        });

        return array_slice($candidates, 0, $limit);
    }

    private function wasRecentlyTouched(StockItem $item, \DateTimeImmutable $now): bool
    {
        $hoursSinceTouch = ($now->getTimestamp() - $item->getUpdatedAt()->getTimestamp()) / 3600;

        return $hoursSinceTouch < self::RECENTLY_TOUCHED_HOURS;
    }

    /** @return list<ReviewReason> */
    private function reasonsFor(StockItem $item, \DateTimeImmutable $now): array
    {
        $reasons = [];

        if ($item->isOpened()) {
            $reasons[] = ReviewReason::Opened;
        }

        if ($item->isQuantityEstimated()) {
            $reasons[] = ReviewReason::QuantityEstimated;
        }

        $effective = $this->effectiveUseBy->compute($item);

        if ($effective->isExpired()) {
            $reasons[] = ReviewReason::Expired;
        } elseif ($effective->isExpiringSoon(self::EXPIRING_SOON_WITHIN_DAYS)) {
            $reasons[] = ReviewReason::ExpiringSoon;
        }

        if ($this->isStale($item, $now)) {
            $reasons[] = ReviewReason::Stale;
        }

        return $reasons;
    }

    /**
     * Un lot est « stale » quand personne ne l'a retouché depuis plus longtemps
     * que sa conservation probable — un seuil par aliment, pas une durée unique :
     * le riz n'est jamais signalé, une salade l'est en quelques jours.
     */
    private function isStale(StockItem $item, \DateTimeImmutable $now): bool
    {
        $variant = $item->getVariant();
        $lastTouch = max($item->getUpdatedAt(), $item->getCreatedAt());
        $daysSinceTouch = $lastTouch->diff($now)->days;

        // In the freezer, a food's own duration is a quality limit, not how
        // long it usually waits: every frozen food waits for months, so the
        // freezer default applies to all of them.
        $expectedShelfLifeDays = StorageLocation::Freezer === $item->getStorageLocation()
            ? $this->shelfLife->defaultDays(StorageLocation::Freezer)
            : $this->shelfLife->estimate(
                $variant->getName(),
                $variant->getCategory(),
                $variant->getTraits(),
                $item->getStorageLocation(),
            )->days;

        return $daysSinceTouch >= $expectedShelfLifeDays;
    }
}
