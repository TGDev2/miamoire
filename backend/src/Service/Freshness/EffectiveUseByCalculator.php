<?php

declare(strict_types=1);

namespace App\Service\Freshness;

use App\Entity\StockItem;

/**
 * Rend un seul jugement d'urgence par lot, utilisé partout où l'application en
 * a besoin — stock, Cuisiner, notifications — pour que ces trois écrans ne
 * puissent plus se contredire.
 *
 * Avant ce service, ce jugement vivait sur `StockItem` lui-même et ne
 * connaissait que la date imprimée ou estimée. Un exemplaire entamé, qui se
 * dégrade pourtant plus vite, n'était jamais mieux traité qu'un exemplaire
 * scellé portant la même date.
 */
class EffectiveUseByCalculator
{
    public function __construct(private readonly ShelfLifeEstimator $estimator)
    {
    }

    public function compute(StockItem $item): EffectiveUseBy
    {
        $printed = $item->getExpirationDate();
        $printedBasis = $item->isExpirationEstimated() ? EffectiveUseBy::BASIS_ESTIMATED : EffectiveUseBy::BASIS_PRINTED;

        if (!$item->isOpened() || null === $item->getOpenedAt()) {
            return new EffectiveUseBy($printed, null === $printed ? EffectiveUseBy::BASIS_UNKNOWN : $printedBasis, null);
        }

        $variant = $item->getVariant();
        $afterOpening = $this->estimator->estimateAfterOpening(
            $variant->getName(),
            $variant->getCategory(),
            $variant->getTraits(),
            $item->getStorageLocation(),
        );

        if (null === $afterOpening) {
            return new EffectiveUseBy($printed, null === $printed ? EffectiveUseBy::BASIS_UNKNOWN : $printedBasis, false);
        }

        $afterOpeningDate = $afterOpening->until($item->getOpenedAt());

        if (null === $printed || $afterOpeningDate < $printed) {
            return new EffectiveUseBy($afterOpeningDate, EffectiveUseBy::BASIS_AFTER_OPENING, true);
        }

        return new EffectiveUseBy($printed, $printedBasis, true);
    }

    public function isExpiringSoon(StockItem $item, int $withinDays = 3): bool
    {
        return $this->compute($item)->isExpiringSoon($withinDays);
    }

    public function isExpired(StockItem $item): bool
    {
        return $this->compute($item)->isExpired();
    }

    /**
     * Remplace l'ancien `StockItem::getStatus()` : même précédence, mais fondée
     * sur l'échéance effective plutôt que sur la seule date imprimée/estimée,
     * et distingue désormais un exemplaire entamé refermé d'un exemplaire
     * ouvert.
     */
    public function status(StockItem $item): string
    {
        return match (true) {
            $item->isArchived() => 'archived',
            $item->isEmpty() => 'empty',
            $this->isExpired($item) => 'expired',
            $this->isExpiringSoon($item) => 'expiring_soon',
            $item->isRunningLow() => 'running_low',
            $item->isPackageResealed() => 'resealed',
            $item->isOpened() => 'opened',
            default => 'sealed',
        };
    }
}
