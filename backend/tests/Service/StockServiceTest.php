<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\Member;
use App\Entity\StockItem;
use App\Enum\FillLevel;
use App\Enum\MovementType;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Exception\StockException;
use App\Service\Freshness\EffectiveUseByCalculator;
use App\Service\Food\FoodTermMatcher;
use App\Service\Freshness\ShelfLifeEstimator;
use App\Service\Product\VariantIdentity;
use App\Service\Stock\StockService;
use App\Service\TextNormalizer;
use App\Service\UnitConverter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class StockServiceTest extends TestCase
{
    private StockService $service;
    private Household $household;
    private Member $member;
    private EffectiveUseByCalculator $effectiveUseBy;

    protected function setUp(): void
    {
        $this->household = new Household('Foyer de test');
        $this->member = new Member($this->household, 'Test');
        $this->service = new StockService(
            $this->createMock(EntityManagerInterface::class),
            new UnitConverter(),
        );
        $this->effectiveUseBy = new EffectiveUseByCalculator(self::estimator());
    }

    private static function estimator(): ShelfLifeEstimator
    {
        $normalizer = new TextNormalizer();

        return new ShelfLifeEstimator($normalizer, new FoodTermMatcher($normalizer));
    }

    /** L'exemple du cahier des charges : 1 000 g de riz, 250 g consommés, 750 g restants. */
    public function testConsumptionLeavesTheExpectedRemainder(): void
    {
        $item = $this->item(1000, Unit::Gram);

        $movement = $this->service->consume($item, 250, Unit::Gram, $this->member);

        self::assertSame(750.0, $item->getRemainingQuantity());
        self::assertSame(MovementType::Consumption, $movement->getType());
        self::assertSame(-250.0, $movement->getQuantity(), 'Le journal enregistre un delta signé.');
        self::assertSame(750.0, $movement->getRemainingAfter());
    }

    /** Le stock ne doit jamais devenir négatif, même si la demande dépasse le restant. */
    public function testConsumptionIsCappedAtWhatRemains(): void
    {
        $item = $this->item(300, Unit::Gram);

        $movement = $this->service->consume($item, 1000, Unit::Gram, $this->member);

        self::assertSame(0.0, $item->getRemainingQuantity());
        self::assertSame(-300.0, $movement->getQuantity(), 'Le mouvement porte la quantité réellement retirée.');
    }

    public function testConsumptionAcceptsAnotherUnitOfTheSameFamily(): void
    {
        $item = $this->item(2000, Unit::Gram);

        $this->service->consume($item, 1.5, Unit::Kilogram, $this->member);

        self::assertSame(500.0, $item->getRemainingQuantity());
    }

    public function testConsumingAnEmptyItemIsRejected(): void
    {
        $item = $this->item(100, Unit::Gram);
        $this->service->consumeAll($item, $this->member);

        $this->expectException(StockException::class);
        $this->service->consume($item, 1, Unit::Gram, $this->member);
    }

    public function testRemovingOnePackageRemovesOnlyThatPackage(): void
    {
        $item = $this->packagedItem(500, 3, Unit::Gram);

        $this->service->consumeOnePackage($item, $this->member);

        self::assertSame(1000.0, $item->getRemainingQuantity());
        self::assertSame(2, $item->getSealedPackageCount());
        self::assertFalse($item->isOpened(), 'Retirer un exemplaire plein n\'en entame aucun.');
    }

    /**
     * L'exemple de la demande : trois sachets de pâtes de 500 g, 250 g utilisés.
     *
     * UN sachet est entamé, les deux autres restent scellés. Le total seul ne
     * savait pas le dire — et c'est précisément ce que l'ancien modèle perdait.
     */
    public function testWithdrawingFromASealedLotOpensExactlyOnePackage(): void
    {
        $item = $this->packagedItem(500, 3, Unit::Gram);

        $this->service->consume($item, 250, Unit::Gram, $this->member);

        self::assertSame(1250.0, $item->getRemainingQuantity());
        self::assertSame(250.0, $item->getOpenPackageQuantity());
        self::assertSame(2, $item->getSealedPackageCount());
        self::assertTrue($item->isOpened(), 'Prélever sur un produit fermé l\'entame.');
        self::assertSame('opened', $this->effectiveUseBy->status($item));
    }

    /**
     * Le défaut signalé : la quantité partait, le produit restait « fermé ».
     *
     * Le contrôle porte sur `openedAt` autant que sur l'état affiché : le premier
     * est ce qui manquait, le second ce que l'utilisateur voyait.
     */
    public function testConsumptionMarksAPreviouslySealedLotAsOpened(): void
    {
        $item = $this->item(1000, Unit::Gram);
        self::assertNull($item->getOpenedAt());

        $this->service->consume($item, 100, Unit::Gram, $this->member);

        self::assertNotNull($item->getOpenedAt());
        self::assertTrue($item->isOpened());
    }

    public function testFinishingTheOpenPackageLeavesTheOthersSealed(): void
    {
        $item = $this->packagedItem(500, 3, Unit::Gram);
        $this->service->consume($item, 250, Unit::Gram, $this->member);

        $this->service->consume($item, 250, Unit::Gram, $this->member);

        self::assertSame(1000.0, $item->getRemainingQuantity());
        self::assertNull($item->getOpenPackageQuantity(), 'Le sachet fini n\'est plus ouvert.');
        self::assertFalse($item->isOpened());
        self::assertSame(2, $item->getSealedPackageCount());
    }

    /** 700 g pris sur des sachets de 500 g : le premier est fini, le second entamé. */
    public function testConsumptionSpansSeveralPackages(): void
    {
        $item = $this->packagedItem(500, 3, Unit::Gram);

        $this->service->consume($item, 700, Unit::Gram, $this->member);

        self::assertSame(800.0, $item->getRemainingQuantity());
        self::assertSame(300.0, $item->getOpenPackageQuantity());
        self::assertSame(1, $item->getSealedPackageCount());
    }

    /** On finit le paquet ouvert avant d'en attaquer un autre. */
    public function testRemovingOnePackageEmptiesTheOpenedOneFirst(): void
    {
        $item = $this->packagedItem(500, 3, Unit::Gram);
        $this->service->consume($item, 100, Unit::Gram, $this->member);

        $this->service->consumeOnePackage($item, $this->member);

        self::assertSame(1000.0, $item->getRemainingQuantity(), 'Seuls les 400 g ouverts sont partis.');
        self::assertFalse($item->isOpened());
        self::assertSame(2, $item->getSealedPackageCount());
    }

    public function testMarkingAsOpenedOpensOneExemplaireNotTheWholeLot(): void
    {
        $item = $this->packagedItem(500, 3, Unit::Gram);

        $this->service->markOpened($item, true);

        self::assertSame(500.0, $item->getOpenPackageQuantity());
        self::assertSame(2, $item->getSealedPackageCount());
        self::assertSame(1500.0, $item->getRemainingQuantity(), 'Ouvrir ne consomme rien.');
    }

    /**
     * Le bug corrigé : « refermer » n'efface plus l'exemplaire entamé.
     *
     * Avant ce correctif, `markOpened($item, false)` appelait `closeOpenPackage()`
     * et perdait `openPackageQuantity`/`openedAt` — un exemplaire refermé
     * redevenait indiscernable d'un exemplaire jamais ouvert.
     */
    public function testResealingKeepsTheOpenPackageQuantityAndOpenedAt(): void
    {
        $item = $this->packagedItem(500, 3, Unit::Gram);
        $this->service->consume($item, 250, Unit::Gram, $this->member);
        $openedAt = $item->getOpenedAt();

        $this->service->markOpened($item, false);

        self::assertTrue($item->isOpened(), 'Refermer ne fait pas disparaître l\'exemplaire entamé.');
        self::assertTrue($item->isPackageResealed());
        self::assertSame(250.0, $item->getOpenPackageQuantity());
        self::assertSame($openedAt, $item->getOpenedAt());
        self::assertSame('resealed', $this->effectiveUseBy->status($item));
    }

    /** Rouvrir un exemplaire refermé ne réinitialise pas sa date de première ouverture. */
    public function testReopeningAResealedPackagePreservesTheOriginalOpenedAt(): void
    {
        $item = $this->packagedItem(500, 3, Unit::Gram);
        $this->service->consume($item, 250, Unit::Gram, $this->member);
        $openedAt = $item->getOpenedAt();
        $this->service->markOpened($item, false);

        $this->service->markOpened($item, true);

        self::assertFalse($item->isPackageResealed());
        self::assertSame($openedAt, $item->getOpenedAt());
        self::assertSame(250.0, $item->getOpenPackageQuantity());
    }

    /**
     * La seule voie qui revient à « jamais ouvert » : une correction explicite
     * et journalisée, distincte du geste physique « refermer ».
     */
    public function testCancelOpeningClearsTheOpenPackageAndJournalsIt(): void
    {
        $item = $this->packagedItem(500, 3, Unit::Gram);
        $this->service->consume($item, 250, Unit::Gram, $this->member);

        $movement = $this->service->cancelOpening($item, $this->member);

        self::assertFalse($item->isOpened());
        self::assertFalse($item->isPackageResealed());
        self::assertNull($item->getOpenedAt());
        self::assertNull($item->getOpenPackageQuantity());
        self::assertNotNull($movement);
        self::assertSame(MovementType::Correction, $movement->getType());
        self::assertSame('Ouverture annulée : erreur de saisie', $movement->getReason());
    }

    public function testCancelOpeningOnAnUnopenedLotDoesNothing(): void
    {
        $item = $this->item(1000, Unit::Gram);

        $movement = $this->service->cancelOpening($item, $this->member);

        self::assertNull($movement);
        self::assertCount(0, $item->getMovements());
    }

    /**
     * « Vérifier mon stock » : confirmer ne change aucune quantité, mais doit
     * faire avancer `updatedAt` — c'est ce qui retire le lot de la prochaine
     * file (voir `StockReviewSelector`), alors que rien d'autre sur le lot n'a
     * changé pour que Doctrine le détecte tout seul.
     */
    public function testConfirmLeavesTheQuantityUntouchedButAdvancesUpdatedAt(): void
    {
        $item = $this->item(500, Unit::Gram);
        $updatedBefore = $item->getUpdatedAt();
        usleep(1000);

        $movement = $this->service->confirm($item, $this->member);

        self::assertSame(500.0, $item->getRemainingQuantity());
        self::assertSame(MovementType::Correction, $movement->getType());
        self::assertSame(0.0, $movement->getQuantity());
        self::assertSame('Confirmé lors de la vérification du stock', $movement->getReason());
        self::assertGreaterThan($updatedBefore, $item->getUpdatedAt());
    }

    /**
     * « Aujourd'hui » : reporter un lot ne change aucune quantité, mais fixe
     * bien `remindAfter` dans le futur — c'est ce qui l'écarte de la
     * prochaine sélection (voir `SaveTodaySelector`).
     */
    public function testSnoozeSetsRemindAfterInTheFutureAndJournalsIt(): void
    {
        $item = $this->item(500, Unit::Gram);
        self::assertNull($item->getRemindAfter());

        $movement = $this->service->snooze($item, $this->member);

        self::assertSame(500.0, $item->getRemainingQuantity());
        self::assertNotNull($item->getRemindAfter());
        self::assertGreaterThan(new \DateTimeImmutable('+1 day'), $item->getRemindAfter());
        self::assertSame(MovementType::Correction, $movement->getType());
        self::assertSame(0.0, $movement->getQuantity());
    }

    public function testFreezeRecalculatesExpirationAndJournalsTheGesture(): void
    {
        $item = $this->item(500, Unit::Gram);
        $item->setExpirationDate(new \DateTimeImmutable('+1 day'));
        $frozenAt = new \DateTimeImmutable();
        $newExpiration = new \DateTimeImmutable('+180 days');

        $movement = $this->service->freeze($item, $this->member, $frozenAt, $newExpiration);

        self::assertSame(StorageLocation::Freezer, $item->getStorageLocation());
        self::assertSame($frozenAt, $item->getFrozenAt());
        self::assertSame($newExpiration, $item->getExpirationDate());
        self::assertTrue($item->isExpirationEstimated());
        self::assertSame(MovementType::Correction, $movement->getType());
        self::assertSame(0.0, $movement->getQuantity());
    }

    /** Un lot est homogène : un réapprovisionnement à une autre date doit créer un nouveau lot. */
    public function testAddQuantityRejectsAMismatchedPurchaseDate(): void
    {
        $item = $this->item(500, Unit::Gram);
        $item->setPurchaseDate(new \DateTimeImmutable('2026-07-01'));

        $this->expectException(StockException::class);
        $this->expectExceptionMessage('nouvelle course');

        $this->service->addQuantity(
            $item,
            500,
            Unit::Gram,
            $this->member,
            purchaseDate: new \DateTimeImmutable('2026-07-15'),
        );
    }

    /** Sans date fournie, l'appelant affirme implicitement le même achat : comportement inchangé. */
    public function testAddQuantityWithoutADateStillMerges(): void
    {
        $item = $this->item(500, Unit::Gram);
        $item->setPurchaseDate(new \DateTimeImmutable('2026-07-01'));

        $this->service->addQuantity($item, 500, Unit::Gram, $this->member);

        self::assertSame(1000.0, $item->getRemainingQuantity());
    }

    /** Une correction ne peut pas laisser un sachet prétendant contenir plus que le lot. */
    public function testCorrectionShrinksTheOpenPackageWithTheLot(): void
    {
        $item = $this->packagedItem(500, 3, Unit::Gram);
        $this->service->consume($item, 250, Unit::Gram, $this->member);

        $this->service->correct($item, 100, Unit::Gram, $this->member);

        self::assertSame(100.0, $item->getRemainingQuantity());
        self::assertSame(100.0, $item->getOpenPackageQuantity());
        self::assertSame(0, $item->getSealedPackageCount());
    }

    public function testEmptyingALotClosesItsOpenPackage(): void
    {
        $item = $this->packagedItem(500, 3, Unit::Gram);
        $this->service->consume($item, 250, Unit::Gram, $this->member);

        $this->service->discard($item, $this->member);

        self::assertFalse($item->isOpened());
        self::assertSame([], $item->getPackages());
    }

    /**
     * Les exemplaires tels que l'application les affiche : l'entamé d'abord,
     * puis les scellés, dans l'ordre où ils partiront.
     */
    public function testPackagesDescribeEachExemplaire(): void
    {
        $item = $this->packagedItem(500, 3, Unit::Gram);
        $this->service->consume($item, 250, Unit::Gram, $this->member);

        self::assertSame(
            [
                ['position' => 1, 'remaining' => 250.0, 'opened' => true],
                ['position' => 2, 'remaining' => 500.0, 'opened' => false],
                ['position' => 3, 'remaining' => 500.0, 'opened' => false],
            ],
            $item->getPackages(),
        );
    }

    public function testDiscreteAdjustmentsGoBothWays(): void
    {
        $item = $this->item(6, Unit::Piece);

        $this->service->adjustDiscreteUnits($item, -1, $this->member);
        self::assertSame(5.0, $item->getRemainingQuantity());

        $this->service->adjustDiscreteUnits($item, 1, $this->member);
        self::assertSame(6.0, $item->getRemainingQuantity());
    }

    /** Une correction n'écrase pas l'historique : elle enregistre l'écart appliqué. */
    public function testCorrectionRecordsTheDelta(): void
    {
        $item = $this->item(1000, Unit::Gram);

        $movement = $this->service->correct($item, 400, Unit::Gram, $this->member, 'Recomptage');

        self::assertSame(400.0, $item->getRemainingQuantity());
        self::assertSame(MovementType::Correction, $movement->getType());
        self::assertSame(-600.0, $movement->getQuantity());
    }

    public function testApproximateFillLevelIsConvertedToAQuantity(): void
    {
        $item = $this->item(1000, Unit::Gram);

        $this->service->applyFillLevel($item, FillLevel::Half, $this->member);

        self::assertSame(500.0, $item->getRemainingQuantity());
        self::assertTrue($item->isQuantityEstimated());
    }

    public function testUnknownFillLevelOnlyFlagsTheItem(): void
    {
        $item = $this->item(1000, Unit::Gram);

        $movement = $this->service->applyFillLevel($item, FillLevel::Unknown, $this->member);

        self::assertNull($movement, 'Une quantité inconnue ne doit pas inventer un chiffre.');
        self::assertSame(1000.0, $item->getRemainingQuantity());
        self::assertTrue($item->isQuantityEstimated());
    }

    /**
     * Le défaut corrigé : un lot déjà bien consommé (1 000 g restant 400 g)
     * ne doit pas voir « moitié » recalculer 50 % de la quantité initiale.
     */
    public function testApproximateFillLevelIsRelativeToWhatRemainsNotInitial(): void
    {
        $item = $this->item(1000, Unit::Gram);
        $this->service->consume($item, 600, Unit::Gram, $this->member);

        $this->service->applyFillLevel($item, FillLevel::Half, $this->member);

        self::assertSame(200.0, $item->getRemainingQuantity());
    }

    /** Un exemplaire entamé identifié : les scellés ne bougent pas. */
    public function testApproximateFillLevelOnlyReducesTheOpenedPackage(): void
    {
        $item = $this->packagedItem(500, 3, Unit::Gram);
        $this->service->consume($item, 250, Unit::Gram, $this->member);

        $this->service->applyFillLevel($item, FillLevel::Half, $this->member);

        $packages = $item->getPackages();
        $opened = array_values(array_filter($packages, static fn (array $package) => $package['opened']));
        $sealed = array_values(array_filter($packages, static fn (array $package) => !$package['opened']));

        self::assertCount(1, $opened);
        self::assertEqualsWithDelta(125.0, $opened[0]['remaining'], 0.001);
        self::assertCount(2, $sealed);
        self::assertSame(500.0, $sealed[0]['remaining']);
        self::assertSame(500.0, $sealed[1]['remaining']);
    }

    /** Plusieurs exemplaires, aucun entamé : ambigu, on ne devine pas lequel. */
    public function testApproximateFillLevelWithNoPackageOpenedFallsBackToTotal(): void
    {
        $item = $this->packagedItem(500, 3, Unit::Gram);

        $this->service->applyFillLevel($item, FillLevel::Half, $this->member);

        self::assertSame(750.0, $item->getRemainingQuantity());
        self::assertFalse($item->isOpened());
    }

    public function testDiscardEmptiesTheLotAndJournalsIt(): void
    {
        $item = $this->item(800, Unit::Gram);

        $movement = $this->service->discard($item, $this->member, 'Moisi');

        self::assertSame(0.0, $item->getRemainingQuantity());
        self::assertSame(MovementType::Deletion, $movement->getType());
        self::assertSame(-800.0, $movement->getQuantity());
    }

    /**
     * Même famille : la quantité physique ne change pas, seule son expression change.
     *
     * Le conditionnement suit le même facteur : convertir le total seul laissait
     * des sachets de « 500 kg » et un décompte d'exemplaires absurde.
     */
    public function testChangingToACompatibleUnitConvertsTheQuantity(): void
    {
        $item = $this->packagedItem(500, 3, Unit::Gram);
        $this->service->consume($item, 250, Unit::Gram, $this->member);

        $movement = $this->service->changeUnit($item, Unit::Kilogram, null, $this->member);

        self::assertSame(Unit::Kilogram, $item->getUnit());
        self::assertSame(1.25, $item->getRemainingQuantity());
        self::assertSame(1.5, $item->getInitialQuantity());
        self::assertSame(0.5, $item->getPackageSize());
        self::assertSame(0.25, $item->getOpenPackageQuantity());
        self::assertSame(2, $item->getSealedPackageCount());
        self::assertInstanceOf(\App\Entity\StockMovement::class, $movement);
        self::assertSame(MovementType::Correction, $movement->getType());
        self::assertSame(0.0, $movement->getQuantity(), 'Rien n\'a été consommé ni ajouté.');
    }

    /** Le cas signalé : « g » choisi au lieu de « unité ». Deviner un poids unitaire est exclu. */
    public function testChangingUnitFamilyWithoutQuantityIsRejected(): void
    {
        $item = $this->item(500, Unit::Gram);

        $this->expectException(StockException::class);
        $this->expectExceptionMessage('unité');

        $this->service->changeUnit($item, Unit::Piece, null, $this->member);
    }

    public function testChangingUnitFamilyRewritesTheQuantity(): void
    {
        $item = $this->item(500, Unit::Gram);

        $movement = $this->service->changeUnit($item, Unit::Piece, 2, $this->member);

        self::assertSame(Unit::Piece, $item->getUnit());
        self::assertSame(2.0, $item->getRemainingQuantity());
        self::assertSame(2.0, $item->getInitialQuantity(), 'Le lot est redéfini, pas complété.');
        self::assertNotNull($movement);
        self::assertStringContainsString('500 g → 2 unité', (string) $movement->getReason());
    }

    public function testRunningLowThresholdReflectsWhatIsLeft(): void
    {
        $item = $this->item(1000, Unit::Gram);
        self::assertFalse($item->isRunningLow());

        $this->service->consume($item, 850, Unit::Gram, $this->member);
        self::assertTrue($item->isRunningLow());
        self::assertSame('running_low', $this->effectiveUseBy->status($item));
    }

    /**
     * Deux défauts corrigés au même endroit.
     *
     * `remove()` emportait d'abord les mouvements en cascade, donc le journal
     * disparaissait dans le seul cas où il servait vraiment. Puis l'archivage a
     * mis la quantité à zéro — un geste annoncé réversible qui détruisait ce
     * qu'il prétendait mettre de côté. Ranger un lot n'est pas le consommer.
     */
    public function testArchivingKeepsWhatTheLotContained(): void
    {
        $item = $this->item(800, Unit::Gram);

        $this->service->archive($item, $this->member, 'Rangement');

        self::assertTrue($item->isArchived());
        self::assertSame('archived', $this->effectiveUseBy->status($item));
        self::assertSame(800.0, $item->getRemainingQuantity(), 'Archiver ne consomme rien.');
        self::assertCount(1, $item->getMovements(), 'Le geste doit rester tracé, et son auteur avec.');

        $movement = $item->getMovements()->first();
        self::assertSame(MovementType::Correction, $movement->getType());
        self::assertSame(0.0, $movement->getQuantity(), 'Rien n\'est sorti du stock : le delta est nul.');
        self::assertSame(800.0, $movement->getRemainingAfter(), 'C\'est lui qui dit ce que le lot contenait.');
        self::assertSame('Rangement', $movement->getReason());
        self::assertSame($this->member, $movement->getCreatedBy());
    }

    /** « Jeter » reste le geste qui vide — et il est distinct d'« archiver ». */
    public function testDiscardingEmptiesWhereArchivingDoesNot(): void
    {
        $discarded = $this->item(800, Unit::Gram);
        $archived = $this->item(800, Unit::Gram);

        $this->service->discard($discarded, $this->member);
        $this->service->archive($archived, $this->member);

        self::assertSame(0.0, $discarded->getRemainingQuantity());
        self::assertSame(800.0, $archived->getRemainingQuantity());
    }

    public function testArchivingIsIdempotent(): void
    {
        $item = $this->item(800, Unit::Gram);

        $this->service->archive($item, $this->member);
        $archivedAt = $item->getArchivedAt();
        $this->service->archive($item, $this->member);

        self::assertSame($archivedAt, $item->getArchivedAt());
        self::assertCount(1, $item->getMovements(), 'Archiver deux fois n\'écrit qu\'un mouvement.');
    }

    /** Le sens même du mot « réversible » : on retrouve le lot tel qu'on l'a laissé. */
    public function testRestoringBringsTheLotBackWithItsQuantity(): void
    {
        $item = $this->item(800, Unit::Gram);
        $this->service->consume($item, 300, Unit::Gram, $this->member);
        $this->service->archive($item, $this->member);

        $this->service->restore($item, $this->member);

        self::assertFalse($item->isArchived());
        self::assertSame(500.0, $item->getRemainingQuantity());
        self::assertCount(3, $item->getMovements(), 'Consommation, archivage, restauration.');
    }

    /** Une date déduite d'une durée de conservation ne se confond pas avec une date lue. */
    public function testEstimatedExpirationIsFlaggedAsSuch(): void
    {
        $item = $this->item(1, Unit::Piece);

        $item->setEstimatedExpirationDate(new \DateTimeImmutable('+4 days'));
        self::assertTrue($item->isExpirationEstimated());

        // Une date saisie ensuite est une certitude : le marqueur tombe.
        $item->setExpirationDate(new \DateTimeImmutable('+10 days'));
        self::assertFalse($item->isExpirationEstimated());
    }

    public function testClearingTheDateAlsoClearsTheEstimateFlag(): void
    {
        $item = $this->item(1, Unit::Piece);
        $item->setEstimatedExpirationDate(new \DateTimeImmutable('+4 days'));

        $item->setExpirationDate(null);

        self::assertFalse($item->isExpirationEstimated());
    }

    /**
     * « Retour de courses » : un nouvel achat retient sa contenance (dans
     * l'unité pivot de la famille) et son emplacement, pour préremplir le
     * prochain scan du même produit.
     */
    public function testAddingALotRemembersItsPackageSizeAndLocation(): void
    {
        $variant = $this->variant(Unit::Kilogram);

        $this->service->add(
            household: $this->household,
            author: $this->member,
            variant: $variant,
            numberOfPackages: 2,
            quantityPerPackage: 0.5,
            unit: Unit::Kilogram,
            location: StorageLocation::Fridge,
        );

        self::assertSame(500.0, $variant->getUsualPackageSize(), 'Retenu en grammes, l\'unité pivot de la masse.');
        self::assertSame(StorageLocation::Fridge, $variant->getUsualStorageLocation());
    }

    /** Le dernier achat écrase le précédent : ce n'est pas une moyenne à entretenir. */
    public function testASecondPurchaseOverwritesThePreviouslyRememberedHabits(): void
    {
        $variant = $this->variant(Unit::Gram);

        $this->service->add(
            household: $this->household,
            author: $this->member,
            variant: $variant,
            numberOfPackages: 1,
            quantityPerPackage: 500,
            unit: Unit::Gram,
            location: StorageLocation::Pantry,
        );
        $this->service->add(
            household: $this->household,
            author: $this->member,
            variant: $variant,
            numberOfPackages: 1,
            quantityPerPackage: 200,
            unit: Unit::Gram,
            location: StorageLocation::Fridge,
        );

        self::assertSame(200.0, $variant->getUsualPackageSize());
        self::assertSame(StorageLocation::Fridge, $variant->getUsualStorageLocation());
    }

    /** Un lot ne peut pas rejoindre une variante qui ne se compte pas dans la même famille. */
    public function testAddingInAnotherUnitFamilyThanTheVariantIsRejected(): void
    {
        $variant = $this->variant(Unit::Gram);

        $this->expectException(StockException::class);

        $this->service->add(
            household: $this->household,
            author: $this->member,
            variant: $variant,
            numberOfPackages: 1,
            quantityPerPackage: 3,
            unit: Unit::Piece,
            location: StorageLocation::Pantry,
        );
    }

    private function item(float $quantity, Unit $unit): StockItem
    {
        return new StockItem($this->household, $this->variant($unit), $quantity, $unit, StorageLocation::Pantry);
    }

    /** Un lot de plusieurs exemplaires identiques — trois sachets de 500 g. */
    private function packagedItem(float $packageSize, int $packages, Unit $unit): StockItem
    {
        $item = $this->item($packageSize * $packages, $unit);
        $item->setNumberOfPackages($packages);
        $item->setPackageSize($packageSize);

        return $item;
    }

    private function variant(Unit $unit): HouseholdProductVariant
    {
        $identity = VariantIdentity::of('produit', $unit->family(), []);

        return new HouseholdProductVariant($this->household, 'Produit', $identity, $identity->slugBase());
    }
}
