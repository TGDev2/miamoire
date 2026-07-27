<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Enum\Unit;
use App\Repository\StockItemRepository;

/**
 * L'ordre de consommation, vérifié là où il est vraiment appliqué : à travers
 * le repository, pas une doublure qui renverrait simplement la liste qu'on lui
 * aurait donnée. Deux règles y coexistent et leur ordre relatif est tout
 * l'enjeu : la date (effective) de péremption d'abord, l'exemplaire entamé
 * ensuite.
 */
class OpenedLotPriorityTest extends DatabaseTestCase
{
    private StockItemRepository $stockItems;
    private Household $household;
    private HouseholdProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var StockItemRepository $stockItems */
        $stockItems = static::getContainer()->get(StockItemRepository::class);
        $this->stockItems = $stockItems;

        $this->household = $this->makeHousehold();
        $this->variant = $this->makeVariant($this->household, 'Pates', Unit::Gram);
    }

    /**
     * Trois sachets identiques, un seul entamé : c'est celui-là qui part.
     *
     * Le cas de la demande. Sans date pour les départager — des pâtes sèches
     * n'en portent souvent aucune — seul l'état d'ouverture peut le faire.
     */
    public function testAnOpenedLotIsServedFirstAmongEqualDates(): void
    {
        $this->makeLot($this->household, $this->variant, 500, Unit::Gram);
        $this->makeLot($this->household, $this->variant, 500, Unit::Gram);
        $third = $this->makeLot($this->household, $this->variant, 500, Unit::Gram);

        $third->withdraw(250);
        $this->entityManager->flush();

        $ordered = $this->stockItems->findAvailableByVariant($this->variant);

        self::assertCount(3, $ordered);
        // Seule la première place est affirmée : les deux sachets scellés sont
        // créés dans la même seconde, et `created_at` ne les départage pas.
        self::assertSame((string) $third->getId(), (string) $ordered[0]->getId());
        self::assertFalse($ordered[1]->isOpened());
        self::assertFalse($ordered[2]->isOpened());
    }

    /**
     * La contre-épreuve, et c'est elle qui compte.
     *
     * « En plus de » la date, pas « à la place de ». Si l'ouverture passait
     * devant, le lot du lendemain serait jeté pendant qu'on finirait un paquet
     * ouvert qui tient encore un mois — la priorité aux produits entamés se
     * paierait en gaspillage, c'est-à-dire exactement ce qu'elle prétend éviter.
     */
    public function testAnOpenedLotNeverOvertakesOneThatExpiresSooner(): void
    {
        $urgent = $this->makeLot($this->household, $this->variant, 500, Unit::Gram, '+1 day');
        $opened = $this->makeLot($this->household, $this->variant, 500, Unit::Gram, '+30 days');

        $opened->withdraw(100);
        $this->entityManager->flush();

        $ordered = $this->stockItems->findAvailableByVariant($this->variant);

        self::assertSame((string) $urgent->getId(), (string) $ordered[0]->getId());
        self::assertSame((string) $opened->getId(), (string) $ordered[1]->getId());
    }

    /** Un lot daté part toujours avant un lot sans date, ouvert ou non. */
    public function testDatedLotsStillComeBeforeUndatedOnes(): void
    {
        $undatedOpened = $this->makeLot($this->household, $this->variant, 500, Unit::Gram);
        $dated = $this->makeLot($this->household, $this->variant, 500, Unit::Gram, '+15 days');

        $undatedOpened->withdraw(100);
        $this->entityManager->flush();

        $ordered = $this->stockItems->findAvailableByVariant($this->variant);

        self::assertSame((string) $dated->getId(), (string) $ordered[0]->getId());
        self::assertSame((string) $undatedOpened->getId(), (string) $ordered[1]->getId());
    }

    /**
     * Un exemplaire terminé ne laisse pas le lot « entamé ».
     *
     * L'état vient de `open_package_quantity`, pas d'`opened_at` : après avoir
     * fini le sachet ouvert, le lot redevient deux paquets scellés et repasse
     * derrière un lot réellement entamé.
     */
    public function testFinishingTheOpenPackagePutsTheLotBackInLine(): void
    {
        $finished = $this->makeLot($this->household, $this->variant, 1500, Unit::Gram);
        $finished->setPackageSize(500);
        $stillOpen = $this->makeLot($this->household, $this->variant, 1500, Unit::Gram);
        $stillOpen->setPackageSize(500);

        $finished->withdraw(500);
        $stillOpen->withdraw(250);
        $this->entityManager->flush();

        self::assertFalse($finished->isOpened());

        $ordered = $this->stockItems->findAvailableByVariant($this->variant);

        self::assertSame((string) $stillOpen->getId(), (string) $ordered[0]->getId());
    }

    /**
     * Le bug corrigé : « refermer » ne devait plus faire perdre sa priorité à
     * l'exemplaire entamé. Avant ce correctif, refermer effaçait
     * `open_package_quantity`, et ce lot retombait au rang d'un lot scellé.
     */
    public function testAResealedLotKeepsItsPriorityOverASealedOne(): void
    {
        $sealed = $this->makeLot($this->household, $this->variant, 500, Unit::Gram);
        $resealed = $this->makeLot($this->household, $this->variant, 500, Unit::Gram);

        $resealed->withdraw(100);
        $resealed->resealOpenPackage();
        $this->entityManager->flush();

        self::assertTrue($resealed->isOpened());
        self::assertTrue($resealed->isPackageResealed());

        $ordered = $this->stockItems->findAvailableByVariant($this->variant);

        self::assertSame((string) $resealed->getId(), (string) $ordered[0]->getId());
        self::assertSame((string) $sealed->getId(), (string) $ordered[1]->getId());
    }
}
