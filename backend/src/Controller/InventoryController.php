<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\ProductTrait;
use App\Http\ApiPresenter;
use App\Http\RequestContext;
use App\Repository\StockItemRepository;
use App\Service\Food\CutSuggester;
use App\Service\Freshness\EffectiveUseByCalculator;
use App\Service\Inventory\InventoryDeadlineBucket;
use App\Service\Inventory\InventoryAggregator;
use App\Service\Inventory\InventoryLine;
use App\Service\Inventory\QuickConsumeCandidate;
use App\Service\Inventory\QuickConsumeSelector;
use App\Service\Inventory\ReviewCandidate;
use App\Service\Inventory\SaveTodayCandidate;
use App\Service\Inventory\SaveTodaySelector;
use App\Service\Inventory\StockReviewSelector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/inventory')]
class InventoryController extends AbstractApiController
{
    /** Inventaire agrégé par ingrédient — c'est aussi ce qui est envoyé à l'IA. */
    #[Route('/summary', methods: ['GET'])]
    public function summary(
        RequestContext $context,
        InventoryAggregator $aggregator,
        StockItemRepository $stockItems,
        EffectiveUseByCalculator $effectiveUseBy,
    ): JsonResponse {
        $household = $context->household();
        $lines = $aggregator->aggregate($household);
        $items = $stockItems->findAvailable($household);

        $expiringSoon = array_values(array_filter($items, static fn ($item) => $effectiveUseBy->isExpiringSoon($item)));
        $expired = array_values(array_filter($items, static fn ($item) => $effectiveUseBy->isExpired($item)));

        return $this->json([
            'ingredients' => array_map(
                static fn (InventoryLine $line): array => [
                    'key' => $line->key,
                    'variantId' => $line->variantId,
                    'label' => $line->label,
                    'name' => $line->name,
                    'quantity' => $line->quantity,
                    'unit' => $line->unit->value,
                    'unitLabel' => $line->unit->label(),
                    'expiresSoon' => $line->expiresSoon,
                    // Trois urgences distinctes : la date qui approche, le produit
                    // frais qui se dégrade sans attendre sa date, et le paquet déjà
                    // ouvert. L'écran « Cuisiner » les affiche toutes les trois.
                    'perishable' => $line->perishable,
                    'alreadyOpened' => $line->alreadyOpened,
                    'earliestExpiration' => $line->earliestExpiration?->format('Y-m-d'),
                    'expirationIsEstimated' => $line->expirationIsEstimated,
                    'quantityIsEstimated' => $line->quantityIsEstimated,
                    'category' => $line->category,
                    'traits' => ProductTrait::toValues($line->traits),
                    // `null` : forme inconnue, jamais « entière ».
                    'cut' => $line->cut?->value,
                    'locations' => $line->locations,
                    'lotCount' => $line->lotCount,
                    // `null` quand « un exemplaire » ne désigne rien de précis :
                    // aliment déjà compté à la pièce, ou lots aux contenances
                    // différentes. L'écran « Cuisiner » n'offre alors pas le choix.
                    'packaging' => null !== $line->packaging ? [
                        'count' => $line->packaging->count,
                        'size' => $line->packaging->size,
                        'unit' => $line->packaging->unit->value,
                        'unitLabel' => $line->packaging->unit->label(),
                    ] : null,
                    // La même quantité ventilée par échéance effective — « 250 g
                    // entamés à utiliser demain, 1 kg scellé plus tard » plutôt
                    // qu'un seul total marqué globalement urgent.
                    'deadlines' => array_map(
                        static fn (InventoryDeadlineBucket $bucket): array => [
                            'quantity' => $bucket->quantity,
                            'useBy' => $bucket->useBy?->format('Y-m-d'),
                            'useByIsEstimated' => $bucket->useByIsEstimated,
                            'opened' => $bucket->opened,
                            'expiresSoon' => $bucket->expiresSoon,
                            'frozen' => $bucket->frozen,
                            'pastBest' => $bucket->pastBest,
                        ],
                        $line->buckets,
                    ),
                ],
                $lines,
            ),
            'stats' => [
                'distinctIngredients' => count($lines),
                'lots' => count($items),
                'expiringSoon' => count($expiringSoon),
                'expired' => count($expired),
            ],
        ]);
    }

    /**
     * La file courte de « Vérifier mon stock » : quelques lots dont la
     * fiabilité est probablement faible, jamais une accusation d'erreur.
     */
    #[Route('/review-queue', methods: ['GET'])]
    public function reviewQueue(
        Request $request,
        RequestContext $context,
        StockReviewSelector $selector,
        ApiPresenter $presenter,
    ): JsonResponse {
        $limit = $request->query->getInt('limit', 8);
        $candidates = $selector->selectForReview($context->household(), $limit);

        return $this->json([
            'items' => array_map(
                static fn (ReviewCandidate $candidate): array => [
                    'stockItem' => $presenter->stockItem($candidate->item),
                    'reasons' => array_map(
                        static fn ($reason): array => ['code' => $reason->value, 'label' => $reason->label()],
                        $candidate->reasons,
                    ),
                ],
                $candidates,
            ),
        ]);
    }

    /**
     * The foods in stock whose cut is unknown although it decides the cooking
     * (meat, fish) — the catch-up list for stock put away before the cut could be
     * given. One lot per food: its cut is the food's, so correcting that lot
     * (`PATCH /api/stock-items/{id}` with `cut`) answers for all of them.
     */
    #[Route('/cut-queue', methods: ['GET'])]
    public function cutQueue(
        RequestContext $context,
        StockItemRepository $stockItems,
        CutSuggester $cutSuggester,
        ApiPresenter $presenter,
    ): JsonResponse {
        $household = $context->household();
        $foods = [];

        foreach ($stockItems->findAvailable($household) as $item) {
            $variant = $item->getVariant();
            $id = (string) $variant->getId();

            if (null !== $variant->getCut()) {
                continue;
            }

            if (isset($foods[$id])) {
                ++$foods[$id]['lotCount'];
                continue;
            }

            $suggestion = $cutSuggester->suggest(
                $household,
                $variant->getName(),
                $variant->getCategory(),
                $variant->getTraits(),
                $variant->getNature(),
            );

            // Meat and fish only: elsewhere the question was mostly noise —
            // "Pommes noisettes" asked whether it was a whole apple.
            if (!$suggestion->matters) {
                continue;
            }

            $foods[$id] = [
                'variant' => $presenter->variant($variant),
                'stockItemId' => (string) $item->getId(),
                'lotCount' => 1,
                'suggestedCut' => $suggestion->cut?->value,
                'suggestedCutSource' => $suggestion->source,
            ];
        }

        $items = array_values($foods);
        usort($items, static fn (array $a, array $b): int => strcmp($a['variant']['culinaryLabel'], $b['variant']['culinaryLabel']));

        return $this->json(['items' => $items]);
    }

    /**
     * La sélection courte de « Aujourd'hui » : quelques lots à traiter
     * maintenant, classés par échéance effective puis exemplaire entamé.
     */
    #[Route('/save-today', methods: ['GET'])]
    public function saveToday(
        Request $request,
        RequestContext $context,
        SaveTodaySelector $selector,
        ApiPresenter $presenter,
    ): JsonResponse {
        $limit = $request->query->getInt('limit', 3);
        $candidates = $selector->selectForToday($context->household(), $limit);

        return $this->json([
            'items' => array_map(
                static fn (SaveTodayCandidate $candidate): array => [
                    'stockItem' => $presenter->stockItem($candidate->item),
                    'reasons' => array_map(
                        static fn ($reason): array => ['code' => $reason->value, 'label' => $reason->label()],
                        $candidate->reasons,
                    ),
                ],
                $candidates,
            ),
        ]);
    }

    /**
     * Les quelques produits les plus plausibles pour « J'ai utilisé quelque
     * chose » : entamés, récemment consommés, utilisés dans une recette ou
     * simplement consultés récemment.
     */
    #[Route('/quick-consume-candidates', methods: ['GET'])]
    public function quickConsumeCandidates(
        Request $request,
        RequestContext $context,
        QuickConsumeSelector $selector,
        ApiPresenter $presenter,
    ): JsonResponse {
        $limit = $request->query->getInt('limit', 8);
        $candidates = $selector->selectForQuickConsume($context->household(), $limit);

        return $this->json([
            'items' => array_map(
                static fn (QuickConsumeCandidate $candidate): array => [
                    'stockItem' => $presenter->stockItem($candidate->item),
                    'reasons' => array_map(
                        static fn ($reason): array => ['code' => $reason->value, 'label' => $reason->label()],
                        $candidate->reasons,
                    ),
                ],
                $candidates,
            ),
        ]);
    }
}
