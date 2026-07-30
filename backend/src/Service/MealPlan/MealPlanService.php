<?php

declare(strict_types=1);

namespace App\Service\MealPlan;

use App\Entity\Household;
use App\Entity\MealPlanEntry;
use App\Enum\MealPlanLineStatus;
use App\Enum\Unit;
use App\Repository\HouseholdProductVariantRepository;
use App\Repository\MealPlanEntryRepository;
use App\Repository\StockItemRepository;
use App\Service\Ai\RecipePlanService;
use App\Service\Ai\Safety\DietaryGuardFactory;
use App\Service\Inventory\SaveTodayCandidate;
use App\Service\Inventory\SaveTodaySelector;
use App\Service\UnitConverter;

/**
 * Calcule la réservation souple de chaque repas planifié : jamais une
 * déduction de stock, seulement ce qui reste disponible une fois les repas
 * plus proches déjà servis, et ce que d'autres repas réclament par ailleurs.
 *
 * Le disponible par variante est recalculé ici même (somme des lots en unité
 * de base), plutôt que réutilisé depuis `InventoryAggregator::aggregate()` :
 * sa sortie est déjà arrondie à l'affichage (« humanized »), ce qui
 * introduirait une double erreur d'arrondi dans le seul calcul du projet qui
 * a besoin de précision d'un repas à l'autre.
 *
 * Le manque cumulé (`shortfallSummary`) est agrégé PAR VARIANTE avant d'être
 * exposé : `ShoppingListService::addMany()` dédoublonne silencieusement deux
 * lignes de la même variante dans un même appel (une liste de courses ne
 * cumule pas, voir sa documentation) — envoyer un manque par ligne d'entrée
 * ferait donc disparaître le second manque dès que deux repas partagent un
 * ingrédient, exactement le cas que cette fonctionnalité existe pour révéler.
 */
class MealPlanService
{
    public const MAX_ACTIVE_ENTRIES = 4;

    private const UNCLAIMED_URGENT_LIMIT = 5;

    public function __construct(
        private readonly MealPlanEntryRepository $entries,
        private readonly StockItemRepository $stockItems,
        private readonly HouseholdProductVariantRepository $variants,
        private readonly UnitConverter $converter,
        private readonly RecipePlanService $planner,
        private readonly DietaryGuardFactory $guards,
        private readonly SaveTodaySelector $today,
    ) {
    }

    /** `$now` n'est injectable que pour les tests, comme ailleurs dans ce service voisin (`SaveTodaySelector`). */
    public function overview(Household $household, ?\DateTimeImmutable $now = null): MealPlanOverview
    {
        $entries = $this->sortChronologically($this->entries->findForHousehold($household));

        $onHand = $this->onHandByVariant($household);

        /** @var array<string, list<array{key: string, label: string, quantity: float, unit: Unit, variantId: ?string}>> $resolvedLines */
        $resolvedLines = [];
        /** @var array<string, list<string>> $demandByVariant */
        $demandByVariant = [];
        /** @var array<string, MealPlanEntry> $entryById */
        $entryById = [];

        foreach ($entries as $entry) {
            $entryId = (string) $entry->getId();
            $entryById[$entryId] = $entry;

            $recipe = $entry->recipeDocument();
            $lines = null !== $recipe ? $this->resolveConsumptionLines($household, $recipe) : [];
            $resolvedLines[$entryId] = $lines;

            foreach ($lines as $line) {
                if (null !== $line['variantId']) {
                    $demandByVariant[$line['variantId']][] = $entryId;
                }
            }
        }

        /** @var array<string, float> $claimed */
        $claimed = [];
        /** @var array<string, float> $shortfallBaseByVariant */
        $shortfallBaseByVariant = [];
        /** @var array<string, string> $shortfallLabelByVariant */
        $shortfallLabelByVariant = [];
        /** @var array<string, Unit> $shortfallUnitByVariant */
        $shortfallUnitByVariant = [];
        /** @var array<string, array{label: string, unit: Unit, quantity: float}> $unresolvedShortfall */
        $unresolvedShortfall = [];

        $entryViews = [];

        foreach ($entries as $entry) {
            $entryId = (string) $entry->getId();
            $mealLines = [];

            foreach ($resolvedLines[$entryId] as $line) {
                if (null === $line['variantId']) {
                    $mealLines[] = new MealPlanLine(
                        $line['key'],
                        $line['label'],
                        $line['quantity'],
                        $line['unit'],
                        MealPlanLineStatus::NeedsReassociation,
                        $line['quantity'],
                        sprintf('« %s » ne correspond plus à aucun aliment de votre inventaire actuel.', $line['label']),
                        [],
                    );

                    $bucketKey = mb_strtolower($line['label']).'|'.$line['unit']->family()->value;
                    $unresolvedShortfall[$bucketKey] ??= ['label' => $line['label'], 'unit' => $line['unit'], 'quantity' => 0.0];
                    $unresolvedShortfall[$bucketKey]['quantity'] += $this->converter->toBase($line['quantity'], $line['unit']);
                    continue;
                }

                $variantId = $line['variantId'];
                $requestedBase = $this->converter->toBase($line['quantity'], $line['unit']);
                $alreadyClaimed = $claimed[$variantId] ?? 0.0;
                $available = max(0.0, ($onHand[$variantId] ?? 0.0) - $alreadyClaimed);
                $coveredBase = min($requestedBase, $available);
                $shortfallBase = max(0.0, $requestedBase - $coveredBase);
                $claimed[$variantId] = $alreadyClaimed + $coveredBase;

                if ($shortfallBase > 0.0001) {
                    $shortfallBaseByVariant[$variantId] = ($shortfallBaseByVariant[$variantId] ?? 0.0) + $shortfallBase;
                    $shortfallLabelByVariant[$variantId] ??= $line['label'];
                    $shortfallUnitByVariant[$variantId] ??= $line['unit'];
                }

                $otherEntryIds = array_values(array_unique(array_filter(
                    $demandByVariant[$variantId] ?? [],
                    static fn (string $id): bool => $id !== $entryId,
                )));
                $alsoClaimedBy = array_map(
                    static fn (string $id): MealPlanCrossReference => MealPlanCrossReference::fromEntry($entryById[$id]),
                    $otherEntryIds,
                );

                $status = $shortfallBase > 0.0001 ? MealPlanLineStatus::Short : MealPlanLineStatus::Covered;
                $detail = MealPlanLineStatus::Short === $status
                    ? sprintf(
                        '« %s » : %s %s demandé(s), %s %s restant(s) une fois les repas précédents servis.',
                        $line['label'],
                        $line['quantity'],
                        $line['unit']->label(),
                        round($available / $line['unit']->factorToBase(), 3),
                        $line['unit']->label(),
                    )
                    : null;

                $mealLines[] = new MealPlanLine(
                    $line['key'],
                    $line['label'],
                    $line['quantity'],
                    $line['unit'],
                    $status,
                    round($shortfallBase / $line['unit']->factorToBase(), 3),
                    $detail,
                    $alsoClaimedBy,
                );
            }

            $recipe = $entry->recipeDocument();
            $plan = null !== $recipe
                ? $this->planner->plan($this->guards->for($household), $household->getStrictExclusionLabels(), $household, $recipe)
                : null;

            $entryViews[] = new MealPlanEntryView($entry, $plan, $mealLines);
        }

        $shortfallSummary = [];
        foreach ($shortfallBaseByVariant as $variantId => $baseQuantity) {
            $baseUnit = $shortfallUnitByVariant[$variantId]->baseUnit();
            $humanized = $this->converter->humanize($baseQuantity, $baseUnit);
            $shortfallSummary[] = new MealPlanShortfallLine($shortfallLabelByVariant[$variantId], $humanized['quantity'], $humanized['unit']);
        }
        foreach ($unresolvedShortfall as $bucket) {
            $humanized = $this->converter->humanize($bucket['quantity'], $bucket['unit']->baseUnit());
            $shortfallSummary[] = new MealPlanShortfallLine($bucket['label'], $humanized['quantity'], $humanized['unit']);
        }

        $claimedVariantIds = array_keys($demandByVariant);
        $unclaimedUrgentItems = array_values(array_filter(
            $this->today->selectForToday($household, self::UNCLAIMED_URGENT_LIMIT, $now),
            static fn (SaveTodayCandidate $candidate): bool => !in_array(
                (string) $candidate->item->getVariant()->getId(),
                $claimedVariantIds,
                true,
            ),
        ));

        return new MealPlanOverview($entryViews, $shortfallSummary, $unclaimedUrgentItems);
    }

    /** @return list<MealPlanEntry> */
    private function sortChronologically(array $entries): array
    {
        usort($entries, static function (MealPlanEntry $a, MealPlanEntry $b): int {
            $dateCompare = $a->getDate() <=> $b->getDate();
            if (0 !== $dateCompare) {
                return $dateCompare;
            }

            $slotCompare = $a->getSlot()->order() <=> $b->getSlot()->order();
            if (0 !== $slotCompare) {
                return $slotCompare;
            }

            return $a->getCreatedAt() <=> $b->getCreatedAt();
        });

        return array_values($entries);
    }

    /** @return array<string, float> quantité disponible par id de variante, en unité de base */
    private function onHandByVariant(Household $household): array
    {
        $onHand = [];

        foreach ($this->stockItems->findAvailable($household) as $item) {
            $variantId = (string) $item->getVariant()->getId();
            $onHand[$variantId] = ($onHand[$variantId] ?? 0.0)
                + $this->converter->toBase($item->getRemainingQuantity(), $item->getUnit());
        }

        return $onHand;
    }

    /**
     * @param array<string, mixed> $recipe
     *
     * @return list<array{key: string, label: string, quantity: float, unit: Unit, variantId: ?string}>
     */
    private function resolveConsumptionLines(Household $household, array $recipe): array
    {
        $lines = [];

        foreach (is_array($recipe['stockConsumptionProposal'] ?? null) ? $recipe['stockConsumptionProposal'] : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $key = is_string($entry['key'] ?? null) ? trim($entry['key']) : '';
            $label = is_string($entry['label'] ?? null) ? $entry['label'] : $key;
            $unit = Unit::tryFrom((string) ($entry['unit'] ?? ''));
            $quantity = is_numeric($entry['quantity'] ?? null) ? (float) $entry['quantity'] : 0.0;

            if ('' === $key || null === $unit || $quantity <= 0) {
                continue;
            }

            $variant = $this->variants->findOneBySlug($household, $key);
            $variantId = null !== $variant && $variant->getUnitFamily() === $unit->family()
                ? (string) $variant->getId()
                : null;

            $lines[] = ['key' => $key, 'label' => $label, 'quantity' => $quantity, 'unit' => $unit, 'variantId' => $variantId];
        }

        return $lines;
    }
}
