<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\Household;
use App\Repository\StockItemRepository;
use App\Service\UnitConverter;

/**
 * Transforme les lots en inventaire structuré.
 *
 * On n'envoie jamais à l'IA une simple liste de noms : elle a besoin de
 * quantités, d'unités et de l'urgence de péremption pour proposer un repas
 * réellement réalisable.
 */
class InventoryAggregator
{
    public function __construct(
        private readonly StockItemRepository $stockItems,
        private readonly UnitConverter $converter,
    ) {
    }

    /** @return list<InventoryLine> */
    public function aggregate(Household $household, int $expiringWithinDays = 3): array
    {
        /** @var array<string, array{label: string, base: float, unit: \App\Enum\Unit, expiresSoon: bool, earliest: ?\DateTimeImmutable, category: ?string, locations: array<string, true>, lots: int}> $grouped */
        $grouped = [];

        foreach ($this->stockItems->findAvailable($household) as $item) {
            $product = $item->getProduct();
            $key = $product->getIngredientKey();

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'label' => $product->getName(),
                    'base' => 0.0,
                    'unit' => $item->getUnit()->baseUnit(),
                    'expiresSoon' => false,
                    'earliest' => null,
                    'category' => $product->getCategory(),
                    'locations' => [],
                    'lots' => 0,
                ];
            }

            // Familles différentes sous la même clé (rare) : on garde la première rencontrée.
            if ($grouped[$key]['unit']->family() !== $item->getUnit()->family()) {
                continue;
            }

            $grouped[$key]['base'] += $this->converter->toBase($item->getRemainingQuantity(), $item->getUnit());
            $grouped[$key]['lots']++;
            $grouped[$key]['locations'][$item->getStorageLocation()->value] = true;

            if ($item->isExpiringSoon($expiringWithinDays)) {
                $grouped[$key]['expiresSoon'] = true;
            }

            $expiration = $item->getExpirationDate();
            if (null !== $expiration && (null === $grouped[$key]['earliest'] || $expiration < $grouped[$key]['earliest'])) {
                $grouped[$key]['earliest'] = $expiration;
            }
        }

        $lines = [];
        foreach ($grouped as $key => $data) {
            $readable = $this->converter->humanize($data['base'], $data['unit']);

            $lines[] = new InventoryLine(
                ingredientKey: $key,
                label: $data['label'],
                quantity: $readable['quantity'],
                unit: $readable['unit'],
                expiresSoon: $data['expiresSoon'],
                earliestExpiration: $data['earliest'],
                category: $data['category'],
                locations: array_keys($data['locations']),
                lotCount: $data['lots'],
            );
        }

        // Ce qui périme bientôt d'abord : c'est ce que l'IA doit prioriser.
        usort($lines, static function (InventoryLine $a, InventoryLine $b): int {
            if ($a->expiresSoon !== $b->expiresSoon) {
                return $a->expiresSoon ? -1 : 1;
            }

            return strcmp($a->label, $b->label);
        });

        return $lines;
    }
}
