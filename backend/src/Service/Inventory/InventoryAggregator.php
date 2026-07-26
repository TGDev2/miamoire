<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\Household;
use App\Entity\StockItem;
use App\Repository\StockItemRepository;
use App\Service\Ai\Safety\DietaryGuard;
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
 */
class InventoryAggregator
{
    public function __construct(
        private readonly StockItemRepository $stockItems,
        private readonly UnitConverter $converter,
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
        /** @var array<string, array{item: StockItem, base: float, expiresSoon: bool, opened: bool, earliest: ?\DateTimeImmutable, estimatedExpiration: bool, estimatedQuantity: bool, locations: array<string, true>, lots: int, notes: array<string, true>}> $grouped */
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
                    'earliest' => null,
                    'estimatedExpiration' => false,
                    'estimatedQuantity' => false,
                    'locations' => [],
                    'lots' => 0,
                    'notes' => [],
                ];
            }

            $grouped[$key]['base'] += $this->converter->toBase($item->getRemainingQuantity(), $item->getUnit());
            $grouped[$key]['lots']++;
            $grouped[$key]['locations'][$item->getStorageLocation()->value] = true;
            $grouped[$key]['estimatedQuantity'] = $grouped[$key]['estimatedQuantity'] || $item->isQuantityEstimated();

            $note = $item->getNote();
            if (null !== $note && '' !== trim($note)) {
                $grouped[$key]['notes'][trim($note)] = true;
            }

            if ($item->isExpiringSoon($expiringWithinDays)) {
                $grouped[$key]['expiresSoon'] = true;
            }

            if ($item->isOpened()) {
                $grouped[$key]['opened'] = true;
            }

            $expiration = $item->getExpirationDate();
            if (null !== $expiration && (null === $grouped[$key]['earliest'] || $expiration < $grouped[$key]['earliest'])) {
                $grouped[$key]['earliest'] = $expiration;
                $grouped[$key]['estimatedExpiration'] = $item->isExpirationEstimated();
            }
        }

        $lines = [];
        foreach ($grouped as $data) {
            $variant = $data['item']->getVariant();
            $readable = $this->converter->humanize($data['base'], $data['item']->getUnit()->baseUnit());

            $lines[] = new InventoryLine(
                key: $variant->getSlug(),
                variantId: (string) $variant->getId(),
                label: $variant->getCulinaryLabel(),
                name: $variant->getName(),
                quantity: $readable['quantity'],
                unit: $readable['unit'],
                expiresSoon: $data['expiresSoon'],
                alreadyOpened: $data['opened'],
                earliestExpiration: $data['earliest'],
                expirationIsEstimated: $data['estimatedExpiration'],
                quantityIsEstimated: $data['estimatedQuantity'],
                category: $variant->getCategory(),
                locations: array_keys($data['locations']),
                lotCount: $data['lots'],
                traits: $variant->getTraits(),
                notes: array_keys($data['notes']),
            );
        }

        // Ce qui périme bientôt d'abord, puis ce qui est déjà entamé : les deux
        // urgences que l'IA doit voir en tête de liste, dans cet ordre. Un paquet
        // ouvert ne prime jamais sur une date qui approche — il vient juste après.
        usort($lines, static function (InventoryLine $a, InventoryLine $b): int {
            if ($a->expiresSoon !== $b->expiresSoon) {
                return $a->expiresSoon ? -1 : 1;
            }

            if ($a->alreadyOpened !== $b->alreadyOpened) {
                return $a->alreadyOpened ? -1 : 1;
            }

            return strcmp($a->label, $b->label);
        });

        return $lines;
    }
}
