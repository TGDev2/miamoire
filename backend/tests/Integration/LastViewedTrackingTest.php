<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Enum\Unit;
use App\Repository\StockItemRepository;
use App\Service\Inventory\ReviewCandidate;
use App\Service\Inventory\StockReviewSelector;

/**
 * `StockItem::touch()` (`#[ORM\PreUpdate]`) et sa colonne `#[ORM\Version]`
 * réagissent à N'IMPORTE QUEL `UPDATE` Doctrine sur la ligne. Ces tests
 * garantissent que {@see StockItemRepository::markViewed()} — qui doit
 * enregistrer une simple consultation — ne déclenche ni l'un ni l'autre :
 * sans quoi regarder une fiche produit ferait perdre son ancienneté à
 * `updatedAt` (donc son signal « stale » dans « Vérifier mon stock ») et
 * invaliderait silencieusement l'`expectedRevision` de la correction suivante.
 */
class LastViewedTrackingTest extends DatabaseTestCase
{
    public function testViewingDoesNotAdvanceUpdatedAtOrRevision(): void
    {
        $household = $this->makeHousehold();
        $variant = $this->makeVariant($household, 'Riz', Unit::Gram);
        $item = $this->makeLot($household, $variant, 500, Unit::Gram);
        $this->entityManager->flush();

        // Précision à la seconde : la colonne est un TIMESTAMP(0), le refresh()
        // qui suit tronquerait sinon les microsecondes et ferait échouer la
        // comparaison même en l'absence de toute régression.
        $updatedAtBefore = $item->getUpdatedAt()->format('Y-m-d H:i:s');
        $revisionBefore = $item->getRevision();

        /** @var StockItemRepository $stockItems */
        $stockItems = static::getContainer()->get(StockItemRepository::class);
        $stockItems->markViewed($item->getId(), $household, new \DateTimeImmutable());
        $this->entityManager->refresh($item);

        self::assertSame($revisionBefore, $item->getRevision());
        self::assertSame($updatedAtBefore, $item->getUpdatedAt()->format('Y-m-d H:i:s'));
        self::assertNotNull($item->getLastViewedAt());
    }

    public function testViewingAnItemDoesNotSuppressItFromTheReviewQueue(): void
    {
        $household = $this->makeHousehold();
        $variant = $this->makeVariant($household, 'Riz', Unit::Gram);
        $item = $this->makeLot($household, $variant, 500, Unit::Gram);
        $item->setQuantityIsEstimated(true);
        $this->entityManager->flush();

        /** @var StockItemRepository $stockItems */
        $stockItems = static::getContainer()->get(StockItemRepository::class);
        $stockItems->markViewed($item->getId(), $household, new \DateTimeImmutable());
        $this->entityManager->refresh($item);

        /** @var StockReviewSelector $selector */
        $selector = static::getContainer()->get(StockReviewSelector::class);
        $candidates = $selector->selectForReview($household, 10, new \DateTimeImmutable('+2 days'));

        self::assertContains(
            (string) $item->getId(),
            array_map(static fn (ReviewCandidate $c): string => (string) $c->item->getId(), $candidates),
        );
    }
}
