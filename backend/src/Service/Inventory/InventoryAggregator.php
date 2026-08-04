<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\Household;
use App\Entity\StockItem;
use App\Repository\StockItemRepository;
use App\Service\Ai\Safety\DietaryGuard;
use App\Service\Food\FreshFoodLexicon;
use App\Service\Freshness\EffectiveUseBy;
use App\Service\Freshness\EffectiveUseByCalculator;
use App\Service\UnitConverter;

/**
 * Transforme les lots en inventaire structuré.
 *
 * On n'envoie jamais à l'IA une simple liste de noms : elle a besoin de
 * quantités, d'unités et de l'urgence de péremption pour proposer un repas
 * réellement réalisable.
 *
 * Le regroupement se fait sur la **variante du foyer**, jamais sur la seule clé
 * d'ingrédient. Trois défauts disparaissent avec ce changement :
 *
 * - des tomates fraîches et des tomates en conserve ne sont plus additionnées
 *   sous l'union de leurs états ;
 * - un lot exprimé dans une autre famille d'unité n'est plus ignoré en silence :
 *   il forme sa propre ligne, puisque la famille fait partie de l'identité ;
 * - `quantityIsEstimated`, porté par les lots depuis le début, remonte enfin
 *   jusqu'au modèle.
 *
 * Au sein d'une variante, la quantité est aussi ventilée par échéance
 * effective ({@see InventoryDeadlineBucket}) : deux lots aux échéances très
 * différentes ne se noient plus dans un seul total assorti d'un booléen
 * global.
 */
class InventoryAggregator
{
    public function __construct(
        private readonly StockItemRepository $stockItems,
        private readonly UnitConverter $converter,
        private readonly EffectiveUseByCalculator $effectiveUseBy,
        private readonly FreshFoodLexicon $lexicon,
    ) {
    }

    /**
     * @param DietaryGuard|null $guard écarte les aliments exclus AVANT l'envoi au
     *                                 modèle : le meilleur moyen qu'une recette ne
     *                                 propose pas un allergène est qu'il ne lui soit
     *                                 jamais montré
     *
     * @return list<InventoryLine>
     */
    public function aggregate(
        Household $household,
        int $expiringWithinDays = 3,
        ?DietaryGuard $guard = null,
    ): array {
        /**
         * @var array<string, array{
         *     item: StockItem,
         *     base: float,
         *     expiresSoon: bool,
         *     opened: bool,
         *     estimatedQuantity: bool,
         *     locations: array<string, true>,
         *     lots: int,
         *     notes: array<string, true>,
         *     buckets: array<string, array{base: float, useBy: ?\DateTimeImmutable, useByIsEstimated: bool, opened: bool, expiresSoon: bool}>,
         * }> $grouped
         */
        $grouped = [];

        foreach ($this->stockItems->findAvailable($household) as $item) {
            $variant = $item->getVariant();
            $key = (string) $variant->getId();

            if (null !== $guard && null !== $guard->inspect($variant->getCulinaryLabel(), 'inventaire')) {
                continue;
            }

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'item' => $item,
                    'base' => 0.0,
                    'expiresSoon' => false,
                    'opened' => false,
                    'estimatedQuantity' => false,
                    'locations' => [],
                    'lots' => 0,
                    'notes' => [],
                    'buckets' => [],
                ];
            }

            $itemBase = $this->converter->toBase($item->getRemainingQuantity(), $item->getUnit());

            $grouped[$key]['base'] += $itemBase;
            $grouped[$key]['lots']++;
            $grouped[$key]['locations'][$item->getStorageLocation()->value] = true;
            $grouped[$key]['estimatedQuantity'] = $grouped[$key]['estimatedQuantity'] || $item->isQuantityEstimated();

            $note = $item->getNote();
            if (null !== $note && '' !== trim($note)) {
                $grouped[$key]['notes'][trim($note)] = true;
            }

            $effective = $this->effectiveUseBy->compute($item);
            $expiresSoon = $effective->isExpiringSoon($expiringWithinDays);
            $opened = $item->isOpened();

            if ($expiresSoon) {
                $grouped[$key]['expiresSoon'] = true;
            }

            if ($opened) {
                $grouped[$key]['opened'] = true;
            }

            $bucketKey = ($effective->date?->format('Y-m-d') ?? 'none').'|'.($opened ? '1' : '0');

            if (!isset($grouped[$key]['buckets'][$bucketKey])) {
                $grouped[$key]['buckets'][$bucketKey] = [
                    'base' => 0.0,
                    'useBy' => $effective->date,
                    'useByIsEstimated' => EffectiveUseBy::BASIS_PRINTED !== $effective->basis,
                    'opened' => $opened,
                    'expiresSoon' => false,
                ];
            }

            $grouped[$key]['buckets'][$bucketKey]['base'] += $itemBase;

            if ($expiresSoon) {
                $grouped[$key]['buckets'][$bucketKey]['expiresSoon'] = true;
            }
        }

        $lines = [];
        foreach ($grouped as $data) {
            $variant = $data['item']->getVariant();
            $baseUnit = $data['item']->getUnit()->baseUnit();
            $readable = $this->converter->humanize($data['base'], $baseUnit);

            $buckets = array_map(
                fn (array $bucket): InventoryDeadlineBucket => new InventoryDeadlineBucket(
                    quantity: round($this->converter->convert($bucket['base'], $baseUnit, $readable['unit']), 3),
                    useBy: $bucket['useBy'],
                    useByIsEstimated: $bucket['useByIsEstimated'],
                    opened: $bucket['opened'],
                    expiresSoon: $bucket['expiresSoon'],
                ),
                array_values($data['buckets']),
            );

            usort($buckets, static function (InventoryDeadlineBucket $a, InventoryDeadlineBucket $b): int {
                if ((null === $a->useBy) !== (null === $b->useBy)) {
                    return null === $a->useBy ? 1 : -1;
                }

                return $a->useBy <=> $b->useBy;
            });

            $earliest = $buckets[0] ?? null;

            $lines[] = new InventoryLine(
                key: $variant->getSlug(),
                variantId: (string) $variant->getId(),
                label: $variant->getCulinaryLabel(),
                name: $variant->getName(),
                quantity: $readable['quantity'],
                unit: $readable['unit'],
                expiresSoon: $data['expiresSoon'],
                alreadyOpened: $data['opened'],
                earliestExpiration: $earliest?->useBy,
                expirationIsEstimated: $earliest?->useByIsEstimated ?? false,
                quantityIsEstimated: $data['estimatedQuantity'],
                category: $variant->getCategory(),
                locations: array_keys($data['locations']),
                lotCount: $data['lots'],
                traits: $variant->getTraits(),
                notes: array_keys($data['notes']),
                buckets: $buckets,
                perishable: $variant->isFresh(),
                // Quand un stock se compte en pièces, la pièce comptée EST la
                // découpe : une escalope, une tranche. Le poids de la découpe
                // prime donc sur celui du lexique, qui décrit l'aliment entier —
                // sans quoi six tranches de poulet vaudraient six blancs.
                approxPieceWeight: $variant->getCut()?->approxPieceWeight()
                    ?? $this->lexicon->find($variant->getName())?->approxPieceWeight,
                cut: $variant->getCut(),
            );
        }

        // Trois urgences, dans cet ordre, et l'ordre est le propos :
        //
        // 1. ce qui périme bientôt — une date proche ne se rattrape pas ;
        // 2. ce qui est frais — une courgette attend une semaine, une conserve
        //    attend deux ans. Sans ce palier, les deux étaient à égalité stricte
        //    tant que la courgette n'entrait pas dans les trois derniers jours,
        //    c'est-à-dire tant qu'il était encore temps de la sauver ;
        // 3. ce qui est déjà entamé — un paquet ouvert se dégrade pendant qu'un
        //    paquet scellé attend.
        usort($lines, static function (InventoryLine $a, InventoryLine $b): int {
            if ($a->expiresSoon !== $b->expiresSoon) {
                return $a->expiresSoon ? -1 : 1;
            }

            if ($a->perishable !== $b->perishable) {
                return $a->perishable ? -1 : 1;
            }

            if ($a->alreadyOpened !== $b->alreadyOpened) {
                return $a->alreadyOpened ? -1 : 1;
            }

            return strcmp($a->label, $b->label);
        });

        return $lines;
    }
}
