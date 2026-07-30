<?php

declare(strict_types=1);

namespace App\Service\Shopping;

use App\Entity\Household;
use App\Enum\ConsumptionConfidence;
use App\Repository\StockMovementRepository;
use App\Service\UnitConverter;

/**
 * Estime, par variante, un débit de consommation quotidien à partir du journal de
 * mouvements — le seul mécanisme d'inférence réellement nouveau introduit par
 * l'Évolution 3 (`tasks/evolutions.md`), les trois autres motifs du conseiller
 * s'appuyant sur des services existants.
 *
 * Volontairement simple et vérifiable plutôt que statistique : une moyenne sur une
 * fenêtre bornée, jamais un modèle. `tasks/evolutions.md` prévient explicitement
 * contre l'IA opaque et contre le risque qu'une mauvaise prédiction soit pire
 * qu'une absence de prédiction — d'où la confiance à trois niveaux, dont le plus
 * bas ({@see ConsumptionConfidence::Low}) n'est jamais traduit en suggestion par
 * {@see ShoppingAdvisorSelector} : il est calculé pour rester auditable, pas pour
 * être montré.
 *
 * Une variante absente du tableau retourné n'a aucun mouvement de consommation
 * dans la fenêtre observée — équivalent à {@see ConsumptionConfidence::None}.
 */
class ConsumptionRateEstimator
{
    private const LOOKBACK_DAYS = 90;
    private const HIGH_MIN_EVENTS = 4;
    private const HIGH_MIN_DAYS = 21;
    private const MEDIUM_MIN_EVENTS = 2;
    private const MEDIUM_MIN_DAYS = 7;

    public function __construct(
        private readonly StockMovementRepository $movements,
        private readonly UnitConverter $converter,
    ) {
    }

    /**
     * `$now` n'est injectable que pour les tests, comme ailleurs dans les
     * sélecteurs voisins (`SaveTodaySelector`, `StockReviewSelector`) :
     * `createdAt` n'est modifiable par aucune API publique, les tests avancent
     * donc `$now` plutôt que d'antidater des mouvements.
     *
     * @return array<string, ConsumptionRateEstimate> id de variante => estimation
     */
    public function estimate(Household $household, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $since = $now->modify(sprintf('-%d days', self::LOOKBACK_DAYS));

        /** @var array<string, array{total: float, count: int, earliest: \DateTimeImmutable}> $byVariant */
        $byVariant = [];

        foreach ($this->movements->findConsumptionSince($household, $since) as $row) {
            $variantId = $row['variantId'];
            $base = abs($this->converter->toBase($row['quantity'], $row['unit']));

            $byVariant[$variantId] ??= ['total' => 0.0, 'count' => 0, 'earliest' => $row['createdAt']];
            $byVariant[$variantId]['total'] += $base;
            ++$byVariant[$variantId]['count'];

            if ($row['createdAt'] < $byVariant[$variantId]['earliest']) {
                $byVariant[$variantId]['earliest'] = $row['createdAt'];
            }
        }

        $estimates = [];
        foreach ($byVariant as $variantId => $data) {
            // Borné à 1 jour minimum : un seul mouvement très récent ne doit jamais
            // produire un débit démesuré par division par une fraction de jour.
            $observedDays = max(1, $data['earliest']->diff($now)->days);

            $estimates[$variantId] = new ConsumptionRateEstimate(
                dailyRateBase: $data['total'] / $observedDays,
                confidence: $this->confidenceFor($data['count'], $observedDays),
                sampleCount: $data['count'],
                observedDays: $observedDays,
            );
        }

        return $estimates;
    }

    private function confidenceFor(int $sampleCount, int $observedDays): ConsumptionConfidence
    {
        if ($sampleCount >= self::HIGH_MIN_EVENTS && $observedDays >= self::HIGH_MIN_DAYS) {
            return ConsumptionConfidence::High;
        }

        if ($sampleCount >= self::MEDIUM_MIN_EVENTS && $observedDays >= self::MEDIUM_MIN_DAYS) {
            return ConsumptionConfidence::Medium;
        }

        return ConsumptionConfidence::Low;
    }
}
