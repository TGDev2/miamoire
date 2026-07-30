<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\Household;
use App\Entity\StockItem;
use App\Enum\QuickConsumeReason;
use App\Repository\StockItemRepository;
use App\Repository\StockMovementRepository;

/**
 * Choisit les quelques produits les plus plausibles pour « J'ai utilisé
 * quelque chose » : un exemplaire entamé, un produit récemment consommé ou
 * utilisé dans une recette, ou simplement consulté récemment.
 *
 * Contrairement à {@see StockReviewSelector} (fiabilité de la donnée) et
 * {@see SaveTodaySelector} (urgence), ce sélecteur n'a rien à voir avec la
 * péremption ou l'exactitude : ni fenêtre de grâce après un geste récent (au
 * contraire, utiliser deux fois le même produit en dix minutes est un
 * comportement normal), ni exclusion des catégories mises en sourdine, ni
 * suspension de `Opened` au congélateur (un sachet entamé et congelé reste
 * plausible à puiser).
 */
class QuickConsumeSelector
{
    private const DEFAULT_LIMIT = 8;
    private const MAX_LIMIT = 10;

    /** Fenêtre de fraîcheur pour une consommation ou un usage en recette. */
    private const ACTIVITY_LOOKBACK_DAYS = 14;

    /** Consulter est un signal plus faible et plus périssable qu'agir. */
    private const VIEWED_LOOKBACK_DAYS = 3;

    public function __construct(
        private readonly StockItemRepository $stockItems,
        private readonly StockMovementRepository $movements,
    ) {
    }

    /** @return list<QuickConsumeCandidate> */
    public function selectForQuickConsume(Household $household, int $limit = self::DEFAULT_LIMIT, ?\DateTimeImmutable $now = null): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $now ??= new \DateTimeImmutable();

        $activitySince = $now->modify(sprintf('-%d days', self::ACTIVITY_LOOKBACK_DAYS));
        $consumedVariantIds = array_flip($this->movements->findVariantIdsRecentlyConsumed($household, $activitySince));
        $recipeVariantIds = array_flip($this->movements->findVariantIdsRecentlyUsedInRecipe($household, $activitySince));
        $viewedSince = $now->modify(sprintf('-%d days', self::VIEWED_LOOKBACK_DAYS));

        /** @var array<string, QuickConsumeCandidate> $byVariant un seul candidat représentatif par variante */
        $byVariant = [];

        foreach ($this->stockItems->findAvailable($household) as $item) {
            $reasons = $this->reasonsFor($item, $consumedVariantIds, $recipeVariantIds, $viewedSince);
            if ([] === $reasons) {
                continue;
            }

            $score = array_sum(array_map(static fn (QuickConsumeReason $reason): int => $reason->weight(), $reasons));
            $variantId = (string) $item->getVariant()->getId();

            // Deux lots du même produit ne doivent pas produire deux lignes : on
            // ne garde que le mieux noté (à égalité, le premier rencontré — déjà
            // trié FEFO par `findAvailable()`, donc le plus pertinent des deux).
            if (!isset($byVariant[$variantId]) || $score > $byVariant[$variantId]->score) {
                $byVariant[$variantId] = new QuickConsumeCandidate($item, $reasons, $score);
            }
        }

        $candidates = array_values($byVariant);

        usort($candidates, static fn (QuickConsumeCandidate $a, QuickConsumeCandidate $b): int => $b->score <=> $a->score);

        return array_slice($candidates, 0, $limit);
    }

    /**
     * @param array<string, int> $consumedVariantIds
     * @param array<string, int> $recipeVariantIds
     *
     * @return list<QuickConsumeReason>
     */
    private function reasonsFor(
        StockItem $item,
        array $consumedVariantIds,
        array $recipeVariantIds,
        \DateTimeImmutable $viewedSince,
    ): array {
        $reasons = [];

        if ($item->isOpened()) {
            $reasons[] = QuickConsumeReason::Opened;
        }

        if (null !== $item->getLastViewedAt() && $item->getLastViewedAt() >= $viewedSince) {
            $reasons[] = QuickConsumeReason::RecentlyViewed;
        }

        $variantId = (string) $item->getVariant()->getId();

        if (isset($consumedVariantIds[$variantId])) {
            $reasons[] = QuickConsumeReason::RecentlyConsumed;
        }

        if (isset($recipeVariantIds[$variantId])) {
            $reasons[] = QuickConsumeReason::RecentlyUsedInRecipe;
        }

        return $reasons;
    }
}
