<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Enum\DataOrigin;
use App\Enum\FoodNature;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Service\Inventory\InventoryAggregator;
use App\Service\Inventory\InventoryLine;

/**
 * L'ordre dans lequel le générateur de recettes découvre l'inventaire.
 *
 * Avant la nature, une courgette et une boîte de pois chiches arrivaient à
 * égalité stricte tant que la courgette n'entrait pas dans ses trois derniers
 * jours — c'est-à-dire tant qu'il était encore temps de la sauver. Le palier
 * « frais » s'insère donc entre la date qui approche et le paquet entamé.
 */
class InventoryFreshPriorityTest extends DatabaseTestCase
{
    public function testFreshComesAfterExpiringButBeforeOpenedAndStable(): void
    {
        $household = $this->makeHousehold();

        $expiring = $this->makeVariant($household, 'Yaourts', Unit::Piece);
        $fresh = $this->makeVariant($household, 'Courgettes', Unit::Piece);
        $opened = $this->makeVariant($household, 'Pates', Unit::Gram);
        $stable = $this->makeVariant($household, 'Pois chiches', Unit::Gram);

        $fresh->setNature(FoodNature::Fresh, DataOrigin::Detected);

        $this->makeLot($household, $expiring, 4, Unit::Piece, 'today +1 day');
        $this->makeLot($household, $fresh, 3, Unit::Piece, 'today +7 days');
        $this->makeLot($household, $stable, 400, Unit::Gram, 'today +400 days');

        $openedLot = $this->makeLot($household, $opened, 500, Unit::Gram, 'today +200 days');
        $openedLot->openNextPackage();

        $this->entityManager->flush();

        /** @var InventoryAggregator $aggregator */
        $aggregator = static::getContainer()->get(InventoryAggregator::class);

        $keys = array_map(
            static fn (InventoryLine $line): string => $line->key,
            $aggregator->aggregate($household),
        );

        self::assertSame(['yaourts', 'courgettes', 'pates', 'pois-chiches'], $keys);
    }

    /**
     * Le marqueur transmis au modèle, et le poids indicatif qui lui permet
     * d'écrire « 2 courgettes, environ 500 g » sans jamais convertir des pièces
     * en grammes dans sa proposition de déduction.
     */
    public function testTheModelIsToldWhatIsPerishable(): void
    {
        $household = $this->makeHousehold();

        $fresh = $this->makeVariant($household, 'Courgettes', Unit::Piece);
        $fresh->setNature(FoodNature::Fresh, DataOrigin::Detected);
        $this->makeLot($household, $fresh, 3, Unit::Piece, 'today +7 days');

        $canned = $this->makeVariant($household, 'Pois chiches', Unit::Gram);
        $this->makeLot($household, $canned, 400, Unit::Gram, 'today +400 days');

        $this->entityManager->flush();

        /** @var InventoryAggregator $aggregator */
        $aggregator = static::getContainer()->get(InventoryAggregator::class);
        $lines = $aggregator->aggregate($household);

        $payloads = [];
        foreach ($lines as $line) {
            $payloads[$line->key] = $line->toAiPayload();
        }

        self::assertTrue($payloads['courgettes']['perishable']);
        self::assertSame('250 g', $payloads['courgettes']['approxWeightPerPiece']);

        // Un aliment conditionné ne porte aucun de ces deux champs : le modèle ne
        // doit pas avoir à distinguer `false` de « non concerné ».
        self::assertArrayNotHasKey('perishable', $payloads['pois-chiches']);
        self::assertArrayNotHasKey('approxWeightPerPiece', $payloads['pois-chiches']);
    }

    /**
     * « Aujourd'hui » ne doit pas signaler un sac de pommes de terre parce qu'il
     * est ouvert : il n'y a pas d'emballage à entamer sur un produit frais. Son
     * urgence vient de sa date, qui existe désormais toujours.
     */
    public function testAnOpenedFreshLotIsNotUrgentByItself(): void
    {
        $household = $this->makeHousehold();

        $fresh = $this->makeVariant($household, 'Pommes de terre', Unit::Gram);
        $fresh->setNature(FoodNature::Fresh, DataOrigin::Detected);

        $lot = $this->makeLot($household, $fresh, 1500, Unit::Gram, 'today +25 days');
        $lot->setStorageLocation(StorageLocation::Pantry);
        $lot->openNextPackage();

        $this->entityManager->flush();

        /** @var \App\Service\Inventory\SaveTodaySelector $selector */
        $selector = static::getContainer()->get(\App\Service\Inventory\SaveTodaySelector::class);

        self::assertSame([], $selector->selectForToday($household));
    }

    /**
     * Une courgette s'affichait « Fermé » sur la liste de stock. Les trois états
     * de fin — scellé, entamé, refermé — décrivent un emballage, et un produit
     * frais n'en a pas. Les états d'urgence, eux, valent pour tout le monde.
     */
    public function testAFreshLotIsNeverLabelledAsSealedOrOpened(): void
    {
        $household = $this->makeHousehold();

        $fresh = $this->makeVariant($household, 'Courgettes', Unit::Piece);
        $fresh->setNature(FoodNature::Fresh, DataOrigin::Detected);
        $lot = $this->makeLot($household, $fresh, 3, Unit::Piece, 'today +7 days');

        $packaged = $this->makeVariant($household, 'Pois chiches', Unit::Gram);
        $sealed = $this->makeLot($household, $packaged, 400, Unit::Gram, 'today +400 days');

        $this->entityManager->flush();

        /** @var \App\Service\Freshness\EffectiveUseByCalculator $status */
        $status = static::getContainer()->get(\App\Service\Freshness\EffectiveUseByCalculator::class);

        self::assertSame('fresh', $status->status($lot));
        self::assertSame('sealed', $status->status($sealed));

        // L'urgence reste l'urgence : la fraîcheur ne masque jamais une date passée.
        $expired = $this->makeLot($household, $fresh, 2, Unit::Piece, 'today -1 day');
        $this->entityManager->flush();

        self::assertSame('expired', $status->status($expired));
    }
}
