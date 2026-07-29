<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\ProductTrait;
use App\Http\ApiPresenter;
use App\Http\RequestContext;
use App\Repository\StockItemRepository;
use App\Service\Freshness\EffectiveUseByCalculator;
use App\Service\Inventory\InventoryDeadlineBucket;
use App\Service\Inventory\InventoryAggregator;
use App\Service\Inventory\InventoryLine;
use App\Service\Inventory\ReviewCandidate;
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
                    // Deux urgences distinctes : la date qui approche, et le paquet
                    // déjà ouvert. L'écran « Cuisiner » les affiche toutes les deux.
                    'alreadyOpened' => $line->alreadyOpened,
                    'earliestExpiration' => $line->earliestExpiration?->format('Y-m-d'),
                    'expirationIsEstimated' => $line->expirationIsEstimated,
                    'quantityIsEstimated' => $line->quantityIsEstimated,
                    'category' => $line->category,
                    'traits' => ProductTrait::toValues($line->traits),
                    'locations' => $line->locations,
                    'lotCount' => $line->lotCount,
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
}
