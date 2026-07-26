<?php

declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\HouseholdProductVariant;
use App\Entity\StockItem;
use App\Enum\Unit;
use App\Repository\StockItemRepository;
use App\Service\UnitConverter;

/**
 * First Expired, First Out.
 *
 * Quand plusieurs lots du même aliment coexistent avec des dates différentes,
 * la consommation retire par défaut du lot qui expire le plus tôt — c'est le
 * comportement qui réduit réellement le gaspillage.
 *
 * L'allocation se fait à l'intérieur d'une variante du foyer : une recette qui
 * demande des tomates fraîches ne peut plus puiser dans les tomates en conserve,
 * et une demande en grammes ne peut plus rencontrer un lot compté à la pièce —
 * ce sont des variantes différentes.
 */
class FefoAllocator
{
    public function __construct(
        private readonly StockItemRepository $stockItems,
        private readonly UnitConverter $converter,
    ) {
    }

    public function plan(
        HouseholdProductVariant $variant,
        float $quantity,
        Unit $unit,
    ): FefoPlan {
        return $this->planFrom($this->stockItems->findAvailableByVariant($variant), $variant, $quantity, $unit);
    }

    /**
     * Le même plan, mais calculé sur des lots verrouillés.
     *
     * Planifier puis écrire laissait une fenêtre : deux téléphones pouvaient
     * planifier la même quantité avant que l'un des deux n'écrive, et le second
     * découvrait le manque au moment du plafonnement — trop tard pour prévenir.
     * Ici, les lots candidats sont verrouillés AVANT que FEFO ne soit recalculé,
     * de sorte que le plan décrit un état que personne d'autre ne peut plus changer
     * jusqu'à la fin de la transaction.
     *
     * À n'appeler qu'à l'intérieur d'une transaction.
     */
    public function planLocked(
        HouseholdProductVariant $variant,
        float $quantity,
        Unit $unit,
    ): FefoPlan {
        $candidates = $this->stockItems->findAvailableByVariant($variant);

        $this->stockItems->lockForUpdate(array_map(
            static fn (StockItem $item) => $item->getId(),
            $candidates,
        ));

        // Relecture après verrouillage : les quantités peuvent avoir changé
        // pendant l'attente du verrou.
        return $this->planFrom($this->stockItems->findAvailableByVariant($variant), $variant, $quantity, $unit);
    }

    /** Quantité totale disponible pour une variante, exprimée dans l'unité demandée. */
    public function availableQuantity(HouseholdProductVariant $variant, Unit $unit): float
    {
        $total = 0.0;

        foreach ($this->stockItems->findAvailableByVariant($variant) as $lot) {
            if ($this->converter->canConvert($unit, $lot->getUnit())) {
                $total += $this->converter->convert($lot->getRemainingQuantity(), $lot->getUnit(), $unit);
            }
        }

        return round($total, 3);
    }

    /**
     * @param list<StockItem> $lots déjà triés FEFO
     */
    private function planFrom(
        array $lots,
        HouseholdProductVariant $variant,
        float $quantity,
        Unit $unit,
    ): FefoPlan {
        $remainingToTake = $quantity;
        $allocations = [];

        foreach ($lots as $lot) {
            if ($remainingToTake <= 0.0001) {
                break;
            }

            // Une variante ne mélange plus les familles d'unité ; la garde reste
            // par sécurité, elle ne doit plus jamais servir.
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
            variant: $variant,
        );
    }
}
