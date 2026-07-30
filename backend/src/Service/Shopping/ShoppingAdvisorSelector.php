<?php

declare(strict_types=1);

namespace App\Service\Shopping;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Enum\ConsumptionConfidence;
use App\Enum\MealPlanLineStatus;
use App\Enum\ShoppingAdviceConfidence;
use App\Enum\ShoppingAdviceKind;
use App\Repository\HouseholdProductVariantRepository;
use App\Service\Inventory\InventoryAggregator;
use App\Service\Inventory\InventoryLine;
use App\Service\MealPlan\MealPlanService;
use App\Service\UnitConverter;
use Symfony\Component\Uid\Uuid;

/**
 * « Qu'est-ce que je ne dois surtout pas racheter ? » — `tasks/evolutions.md`
 * (Évolution 3). Un seul motif retenu par variante, dans cet ordre de priorité :
 * péremption proche, repas planifiés déjà couverts, stock abondant, rupture
 * proche. Les deux premiers et une des deux voies du troisième sont des faits
 * déjà calculés ailleurs (`InventoryAggregator`, `MealPlanService`) ; seule la
 * rupture proche et l'abondance-par-rythme s'appuient sur
 * {@see ConsumptionRateEstimator}, le seul mécanisme prédictif de cette
 * fonctionnalité — et il reste muet ({@see ConsumptionConfidence::Low}/`None`)
 * plutôt que de deviner.
 *
 * Une variante mutée par le foyer ({@see HouseholdProductVariant::isShoppingAdviceMuted()})
 * n'est jamais évaluée.
 */
class ShoppingAdvisorSelector
{
    private const DEFAULT_LIMIT = 6;
    private const MAX_LIMIT = 10;
    private const RUNNING_OUT_WITHIN_DAYS = 3;
    private const ABUNDANT_DAYS_OF_SUPPLY = 10;

    public function __construct(
        private readonly InventoryAggregator $inventory,
        private readonly ConsumptionRateEstimator $rateEstimator,
        private readonly MealPlanService $mealPlan,
        private readonly HouseholdProductVariantRepository $variants,
        private readonly UnitConverter $converter,
    ) {
    }

    /**
     * `$now` n'est injectable que pour les tests, comme les autres sélecteurs
     * du module (`SaveTodaySelector`, `StockReviewSelector`).
     *
     * @return list<ShoppingAdvisorCandidate>
     */
    public function select(Household $household, int $limit = self::DEFAULT_LIMIT, ?\DateTimeImmutable $now = null): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $now ??= new \DateTimeImmutable();

        $lines = $this->inventory->aggregate($household);
        if ([] === $lines) {
            return [];
        }

        $rates = $this->rateEstimator->estimate($household, $now);
        $mealCoverage = $this->mealCoverageBySlug($household, $now);

        $candidates = [];

        foreach ($lines as $line) {
            $variant = $this->variants->find(Uuid::fromString($line->variantId));
            if (null === $variant || $variant->isShoppingAdviceMuted()) {
                continue;
            }

            $candidate = $this->evaluate(
                $line,
                $variant,
                $rates[$line->variantId] ?? null,
                $mealCoverage[$line->key] ?? null,
                $now,
            );

            if (null !== $candidate) {
                $candidates[] = $candidate;
            }

            if (count($candidates) >= $limit) {
                break;
            }
        }

        return $candidates;
    }

    /**
     * @param array{sufficient: bool, entryCount: int}|null $meal
     */
    private function evaluate(
        InventoryLine $line,
        HouseholdProductVariant $variant,
        ?ConsumptionRateEstimate $rate,
        ?array $meal,
        \DateTimeImmutable $now,
    ): ?ShoppingAdvisorCandidate {
        if ($line->expiresSoon) {
            return new ShoppingAdvisorCandidate(
                $variant,
                ShoppingAdviceKind::UseSoonDontAdd,
                ShoppingAdviceConfidence::Certaine,
                sprintf('%s : à consommer bientôt, n\'en rachetez pas', $line->label),
                sprintf(
                    'Il reste %s %s, à consommer %s — inutile d\'en racheter avant.',
                    $this->formatQuantity($line->quantity),
                    $line->unit->label(),
                    $this->relativeDayLabel($line->earliestExpiration, $now),
                ),
            );
        }

        if (null !== $meal && $meal['sufficient']) {
            return new ShoppingAdvisorCandidate(
                $variant,
                ShoppingAdviceKind::SufficientForPlannedMeals,
                ShoppingAdviceConfidence::Certaine,
                sprintf('%s : quantité suffisante pour les repas prévus', $line->label),
                sprintf(
                    'Le stock actuel (%s %s) couvre %d repas déjà planifié%s.',
                    $this->formatQuantity($line->quantity),
                    $line->unit->label(),
                    $meal['entryCount'],
                    $meal['entryCount'] > 1 ? 's' : '',
                ),
            );
        }

        $onHandBase = $this->converter->toBase($line->quantity, $line->unit);
        $reliableRate = null !== $rate && $rate->dailyRateBase > 0.0 && ConsumptionConfidence::Low !== $rate->confidence;

        if ($reliableRate && $onHandBase >= $rate->dailyRateBase * self::ABUNDANT_DAYS_OF_SUPPLY) {
            return new ShoppingAdvisorCandidate(
                $variant,
                ShoppingAdviceKind::DontRebuy,
                $this->toAdviceConfidence($rate->confidence),
                sprintf('%s : ne pas racheter', $line->label),
                sprintf(
                    'À ce rythme (~%s %s/jour), il y en a pour encore %d jours.',
                    $this->formatQuantity($rate->dailyRateBase),
                    $line->unit->label(),
                    (int) floor($onHandBase / $rate->dailyRateBase),
                ),
            );
        }

        $usualPackageSize = $variant->getUsualPackageSize();
        if (null !== $usualPackageSize && $onHandBase >= $usualPackageSize) {
            $usualHumanized = $this->converter->humanize($usualPackageSize, $variant->getUnitFamily()->baseUnit());

            return new ShoppingAdvisorCandidate(
                $variant,
                ShoppingAdviceKind::DontRebuy,
                ShoppingAdviceConfidence::Certaine,
                sprintf('%s : ne pas racheter', $line->label),
                sprintf(
                    '%s %s disponibles, déjà l\'équivalent d\'un achat habituel (%s %s).',
                    $this->formatQuantity($line->quantity),
                    $line->unit->label(),
                    $this->formatQuantity($usualHumanized['quantity']),
                    $usualHumanized['unit']->label(),
                ),
            );
        }

        if ($reliableRate) {
            $daysRemaining = $onHandBase / $rate->dailyRateBase;

            if ($daysRemaining <= self::RUNNING_OUT_WITHIN_DAYS) {
                $roundedDays = max(0, (int) floor($daysRemaining));

                return new ShoppingAdvisorCandidate(
                    $variant,
                    ShoppingAdviceKind::RunningOut,
                    $this->toAdviceConfidence($rate->confidence),
                    sprintf(
                        '%s : probablement épuisé dans %s',
                        $line->label,
                        1 === $roundedDays ? '1 jour' : sprintf('%d jours', $roundedDays),
                    ),
                    sprintf(
                        'À ce rythme (~%s %s/jour) et %s %s restants, la réserve ne devrait pas durer plus de %d jour(s).',
                        $this->formatQuantity($rate->dailyRateBase),
                        $line->unit->label(),
                        $this->formatQuantity($line->quantity),
                        $line->unit->label(),
                        $roundedDays,
                    ),
                );
            }
        }

        return null;
    }

    /**
     * Couverture des repas planifiés par variante (clé = slug, comme
     * `MealPlanLine::$key`) : « suffisant » si au moins un repas la réclame et
     * qu'aucun n'est en manque dessus. Recalculé ici plutôt qu'exposé par
     * `MealPlanService` : c'est la seule fonctionnalité qui a besoin de ce
     * regroupement par variante plutôt que par repas.
     *
     * @return array<string, array{sufficient: bool, entryCount: int}>
     */
    private function mealCoverageBySlug(Household $household, \DateTimeImmutable $now): array
    {
        $overview = $this->mealPlan->overview($household, $now);

        /** @var array<string, bool> $short */
        $short = [];
        /** @var array<string, int> $entryCount */
        $entryCount = [];

        foreach ($overview->entries as $entryView) {
            $slugsInEntry = [];

            foreach ($entryView->lines as $line) {
                if (MealPlanLineStatus::NeedsReassociation === $line->status) {
                    continue;
                }

                if (MealPlanLineStatus::Short === $line->status) {
                    $short[$line->key] = true;
                }

                $slugsInEntry[$line->key] = true;
            }

            foreach (array_keys($slugsInEntry) as $slug) {
                $entryCount[$slug] = ($entryCount[$slug] ?? 0) + 1;
            }
        }

        $coverage = [];
        foreach ($entryCount as $slug => $count) {
            $coverage[$slug] = ['sufficient' => !($short[$slug] ?? false), 'entryCount' => $count];
        }

        return $coverage;
    }

    private function toAdviceConfidence(ConsumptionConfidence $confidence): ShoppingAdviceConfidence
    {
        return ConsumptionConfidence::High === $confidence ? ShoppingAdviceConfidence::Elevee : ShoppingAdviceConfidence::Moyenne;
    }

    private function relativeDayLabel(?\DateTimeImmutable $date, \DateTimeImmutable $now): string
    {
        if (null === $date) {
            return 'bientôt';
        }

        $days = (int) $now->setTime(0, 0)->diff($date->setTime(0, 0))->format('%r%a');

        return match (true) {
            $days <= 0 => 'aujourd\'hui',
            1 === $days => 'demain',
            $days <= 6 => sprintf('dans %d jours', $days),
            default => sprintf('le %s', $date->format('d/m')),
        };
    }

    private function formatQuantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 2, ',', ''), '0'), ',');
    }
}
