<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\Household;
use App\Entity\StockItem;
use App\Enum\UrgencyReason;
use App\Repository\StockItemRepository;
use App\Service\Freshness\EffectiveUseByCalculator;

/**
 * Choisit un très petit nombre de lots à traiter maintenant, pour
 * l'onglet « Aujourd'hui ».
 *
 * Le classement reprend tel quel l'ordre FEFO de
 * {@see StockItemRepository::findAvailable()} (échéance effective croissante,
 * puis exemplaire entamé d'abord) : c'est déjà exactement « date la plus
 * proche, produit déjà ouvert », sans dupliquer cette logique. Ce service
 * n'ajoute qu'un filtre — écarter ce qui n'est pas urgent, et ce qui a été
 * reporté — jamais un second tri.
 *
 * La quantité et la compatibilité culinaire ne sont volontairement pas des
 * critères de sélection ici : la première ne change presque jamais l'ordre
 * dans les cas réels, la seconde est justement ce que la génération de
 * recette doit pouvoir juger — et refuser — plus loin dans le parcours.
 */
class SaveTodaySelector
{
    private const DEFAULT_LIMIT = 3;
    private const MAX_LIMIT = 5;
    private const EXPIRING_SOON_WITHIN_DAYS = 3;

    public function __construct(
        private readonly StockItemRepository $stockItems,
        private readonly EffectiveUseByCalculator $effectiveUseBy,
    ) {
    }

    /**
     * `$now` n'est injectable que pour les tests : simuler qu'un report a
     * expiré sans attendre réellement le délai (voir `StockService::snooze()`).
     *
     * @return list<SaveTodayCandidate>
     */
    public function selectForToday(Household $household, int $limit = self::DEFAULT_LIMIT, ?\DateTimeImmutable $now = null): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $now ??= new \DateTimeImmutable();

        $candidates = [];

        foreach ($this->stockItems->findAvailable($household) as $item) {
            $remindAfter = $item->getRemindAfter();
            if (null !== $remindAfter && $remindAfter > $now) {
                continue;
            }

            $reasons = $this->reasonsFor($item);
            if ([] === $reasons) {
                continue;
            }

            $candidates[] = new SaveTodayCandidate($item, $reasons);

            if (count($candidates) >= $limit) {
                break;
            }
        }

        return $candidates;
    }

    /** @return list<UrgencyReason> */
    private function reasonsFor(StockItem $item): array
    {
        $reasons = [];
        $effective = $this->effectiveUseBy->compute($item);

        if ($effective->isExpired()) {
            $reasons[] = UrgencyReason::Expired;
        } elseif ($effective->isExpiringSoon(self::EXPIRING_SOON_WITHIN_DAYS)) {
            $reasons[] = UrgencyReason::ExpiringSoon;
        }

        if ($item->isOpened()) {
            $reasons[] = UrgencyReason::Opened;
        }

        return $reasons;
    }
}
