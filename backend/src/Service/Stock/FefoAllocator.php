<?php

declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\Household;
use App\Enum\Unit;
use App\Repository\StockItemRepository;
use App\Service\UnitConverter;

/**
 * First Expired, First Out.
 *
 * Quand plusieurs lots du même aliment coexistent avec des dates différentes,
 * la consommation retire par défaut du lot qui expire le plus tôt — c'est le
 * comportement qui réduit réellement le gaspillage.
 */
class FefoAllocator
{
    public function __construct(
        private readonly StockItemRepository $stockItems,
        private readonly UnitConverter $converter,
    ) {
    }

    public function plan(Household $household, string $ingredientKey, float $quantity, Unit $unit): FefoPlan
    {
        $lots = $this->stockItems->findAvailableByIngredientKey($household, $ingredientKey);

        $remainingToTake = $quantity;
        $allocations = [];

        foreach ($lots as $lot) {
            if ($remainingToTake <= 0.0001) {
                break;
            }

            // Un lot en litres ne peut pas servir une demande en grammes.
            if (!$this->converter->canConvert($unit, $lot->getUnit())) {
                continue;
            }

            $availableInRequestedUnit = $this->converter->convert(
                $lot->getRemainingQuantity(),
                $lot->getUnit(),
                $unit,
            );

            $take = min($remainingToTake, $availableInRequestedUnit);
            if ($take <= 0.0001) {
                continue;
            }

            $allocations[] = new FefoAllocation(
                $lot,
                round($this->converter->convert($take, $unit, $lot->getUnit()), 3),
                $lot->getUnit(),
            );

            $remainingToTake -= $take;
        }

        $shortfall = max(0.0, round($remainingToTake, 3));

        return new FefoPlan(
            allocations: $allocations,
            requested: $quantity,
            allocated: round($quantity - $shortfall, 3),
            shortfall: $shortfall,
            unit: $unit,
        );
    }

    /** Quantité totale disponible pour un ingrédient, exprimée dans l'unité demandée. */
    public function availableQuantity(Household $household, string $ingredientKey, Unit $unit): float
    {
        $total = 0.0;

        foreach ($this->stockItems->findAvailableByIngredientKey($household, $ingredientKey) as $lot) {
            if ($this->converter->canConvert($unit, $lot->getUnit())) {
                $total += $this->converter->convert($lot->getRemainingQuantity(), $lot->getUnit(), $unit);
            }
        }

        return round($total, 3);
    }
}
