<?php

declare(strict_types=1);

namespace App\Service\Freshness;

use App\Entity\StockItem;
use App\Enum\StorageLocation;

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
    /** "À consommer dans les 24 heures après décongélation", as printed on frozen food. */
    private const THAWED_DAYS = 1;

    public function __construct(private readonly ShelfLifeEstimator $estimator)
    {
    }

    /**
     * Freezing stops the clock, without erasing the printed date:
     *
     * - in the freezer, the lot is judged on a quality limit — the later of
     *   its printed date and its freezer duration counted from the day it
     *   went in. The after-opening clock is suspended as well;
     * - out of the freezer, a thawed lot is eaten within 24 hours;
     * - unless it was frozen after its printed date had passed: freezing does
     *   not undo that, and the lot is judged exactly as if it had never been
     *   frozen. An estimated date is a guess and never triggers this.
     */
    public function compute(StockItem $item): EffectiveUseBy
    {
        if (!$this->wasFrozenTooLate($item)) {
            if (StorageLocation::Freezer === $item->getStorageLocation()) {
                return $this->inTheFreezer($item);
            }

            if (null !== $item->getThawedAt()) {
                return new EffectiveUseBy(
                    $item->getThawedAt()->setTime(0, 0)->modify(sprintf('+%d day', self::THAWED_DAYS)),
                    EffectiveUseBy::BASIS_THAWED,
                    null,
                );
            }
        }

        return $this->beforeFreezing($item);
    }

    private function inTheFreezer(StockItem $item): EffectiveUseBy
    {
        $variant = $item->getVariant();
        $start = $item->getFrozenAt() ?? $item->getPurchaseDate() ?? $item->getCreatedAt();
        $quality = $this->estimator
            ->estimate($variant->getName(), $variant->getCategory(), $variant->getTraits(), StorageLocation::Freezer)
            ->until($start->setTime(0, 0));
        $printed = $item->getExpirationDate();

        return new EffectiveUseBy(null !== $printed && $printed > $quality ? $printed : $quality, EffectiveUseBy::BASIS_FROZEN, null);
    }

    private function wasFrozenTooLate(StockItem $item): bool
    {
        $frozenAt = $item->getFrozenAt();
        $printed = $item->getExpirationDate();

        return null !== $frozenAt
            && null !== $printed
            && !$item->isExpirationEstimated()
            && $printed < $frozenAt->setTime(0, 0);
    }

    private function beforeFreezing(StockItem $item): EffectiveUseBy
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
     *
     * Les trois derniers états décrivent un emballage, et un produit frais n'en a
     * pas : une courgette s'affichait « Fermé » sur la liste de stock, ce qui ne
     * décrit rien de réel. Elle est simplement « Frais » tant que sa date le
     * permet — les états d'urgence, eux, valent pour tout le monde.
     */
    public function status(StockItem $item): string
    {
        $effective = $this->compute($item);

        return match (true) {
            $item->isArchived() => 'archived',
            $item->isEmpty() => 'empty',
            $effective->isExpired() => 'expired',
            $effective->isExpiringSoon() => 'expiring_soon',
            $effective->isPastBest() => 'frozen_past_best',
            $item->isRunningLow() => 'running_low',
            $item->getVariant()->isFresh() => 'fresh',
            $item->isPackageResealed() => 'resealed',
            $item->isOpened() => 'opened',
            default => 'sealed',
        };
    }
}
