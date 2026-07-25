<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\RequestContext;
use App\Repository\StockItemRepository;
use App\Service\Inventory\InventoryAggregator;
use App\Service\Inventory\InventoryLine;
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
    ): JsonResponse {
        $household = $context->household();
        $lines = $aggregator->aggregate($household);
        $items = $stockItems->findAvailable($household);

        $expiringSoon = array_values(array_filter($items, static fn ($item) => $item->isExpiringSoon()));
        $expired = array_values(array_filter($items, static fn ($item) => $item->isExpired()));

        return $this->json([
            'ingredients' => array_map(
                static fn (InventoryLine $line): array => [
                    'key' => $line->ingredientKey,
                    'label' => $line->label,
                    'quantity' => $line->quantity,
                    'unit' => $line->unit->value,
                    'unitLabel' => $line->unit->label(),
                    'expiresSoon' => $line->expiresSoon,
                    'earliestExpiration' => $line->earliestExpiration?->format('Y-m-d'),
                    'category' => $line->category,
                    'locations' => $line->locations,
                    'lotCount' => $line->lotCount,
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
}
