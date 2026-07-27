<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Enum\Unit;
use App\Repository\StockItemRepository;
use App\Service\Stock\StockService;

/**
 * Pourquoi les archives étaient introuvables — et pourquoi ce test est en base.
 *
 * Deux règles séparées se neutralisaient, chacune raisonnable prise seule :
 * l'archivage mettait la quantité à zéro, et la recherche écarte par défaut les
 * lots vides (`remainingQuantity > 0`). `GET /stock-items?archived=true` ne
 * pouvait donc renvoyer aucune ligne, jamais. Aucun test de service ne pouvait
 * le voir : le défaut ne vit ni dans `StockService` ni dans le dépôt, mais dans
 * leur rencontre — c'est-à-dire dans le SQL réellement exécuté.
 */
class ArchivedLotTest extends DatabaseTestCase
{
    public function testAnArchivedLotIsFoundBackWithItsQuantity(): void
    {
        $household = $this->makeHousehold();
        $member = $this->makeMember($household);
        $variant = $this->makeVariant($household, 'Riz', Unit::Gram);
        $item = $this->makeLot($household, $variant, 800, Unit::Gram);
        $this->entityManager->flush();

        $this->stockService()->archive($item, $member, 'Rangement');

        $archived = $this->repository()->search($household, ['archived' => true]);

        self::assertCount(1, $archived, 'Un lot archivé doit rester consultable.');
        self::assertSame(800.0, $archived[0]->getRemainingQuantity());
        self::assertSame(800.0, $this->remainingInDatabase($item), 'Et la base doit le dire aussi.');
    }

    /** L'inventaire courant, lui, ne doit pas le voir : c'est tout l'objet du geste. */
    public function testAnArchivedLotLeavesTheActiveInventory(): void
    {
        $household = $this->makeHousehold();
        $member = $this->makeMember($household);
        $variant = $this->makeVariant($household, 'Riz', Unit::Gram);
        $item = $this->makeLot($household, $variant, 800, Unit::Gram);
        $this->entityManager->flush();

        $this->stockService()->archive($item, $member);

        self::assertCount(0, $this->repository()->search($household, []));
        self::assertCount(0, $this->repository()->findAvailable($household), 'Ni les recettes.');
        self::assertCount(0, $this->repository()->findAvailableByVariant($variant), 'Ni le FEFO.');
    }

    public function testRestoringPutsItBackInTheActiveInventory(): void
    {
        $household = $this->makeHousehold();
        $member = $this->makeMember($household);
        $variant = $this->makeVariant($household, 'Riz', Unit::Gram);
        $item = $this->makeLot($household, $variant, 800, Unit::Gram);
        $this->entityManager->flush();

        $service = $this->stockService();
        $service->archive($item, $member);
        $service->restore($item, $member);

        $active = $this->repository()->search($household, []);

        self::assertCount(1, $active);
        self::assertSame(800.0, $active[0]->getRemainingQuantity());
    }

    private function stockService(): StockService
    {
        /** @var StockService $service */
        $service = static::getContainer()->get(StockService::class);

        return $service;
    }

    private function repository(): StockItemRepository
    {
        /** @var StockItemRepository $repository */
        $repository = static::getContainer()->get(StockItemRepository::class);

        return $repository;
    }
}
