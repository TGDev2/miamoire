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
use App\Service\Product\VariantIdentity;
use App\Service\Stock\StockService;
use App\Service\UnitConverter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class StockServiceTest extends TestCase
{
    private StockService $service;
    private Household $household;
    private Member $member;

    protected function setUp(): void
    {
        $this->household = new Household('Foyer de test');
        $this->member = new Member($this->household, 'Test');
        $this->service = new StockService(
            $this->createMock(EntityManagerInterface::class),
            new UnitConverter(),
        );
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
        $item = $this->item(1500, Unit::Gram);
        $item->setNumberOfPackages(3);

        $this->service->consumeOnePackage($item, $this->member);

        self::assertSame(1000.0, $item->getRemainingQuantity());
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

    public function testDiscardEmptiesTheLotAndJournalsIt(): void
    {
        $item = $this->item(800, Unit::Gram);

        $movement = $this->service->discard($item, $this->member, 'Moisi');

        self::assertSame(0.0, $item->getRemainingQuantity());
        self::assertSame(MovementType::Deletion, $movement->getType());
        self::assertSame(-800.0, $movement->getQuantity());
    }

    /** Même famille : la quantité physique ne change pas, seule son expression change. */
    public function testChangingToACompatibleUnitConvertsTheQuantity(): void
    {
        $item = $this->item(1500, Unit::Gram);

        $movement = $this->service->changeUnit($item, Unit::Kilogram, null, $this->member);

        self::assertSame(Unit::Kilogram, $item->getUnit());
        self::assertSame(1.5, $item->getRemainingQuantity());
        self::assertSame(1.5, $item->getInitialQuantity());
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
        self::assertSame('running_low', $item->getStatus());
    }

    /**
     * Le défaut corrigé : `remove()` emportait les mouvements en cascade, donc le
     * journal disparaissait dans le seul cas où il servait vraiment.
     */
    public function testArchivingKeepsTheHistoryAndJournalsWhatWasLeft(): void
    {
        $item = $this->item(800, Unit::Gram);

        $this->service->archive($item, $this->member, 'Rangement');

        self::assertTrue($item->isArchived());
        self::assertSame('archived', $item->getStatus());
        self::assertSame(0.0, $item->getRemainingQuantity());
        self::assertCount(1, $item->getMovements(), 'Le retrait doit rester tracé.');
        self::assertSame(MovementType::Deletion, $item->getMovements()->first()->getType());
        self::assertSame(-800.0, $item->getMovements()->first()->getQuantity());
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

    public function testRestoringBringsTheLotBackWithATrace(): void
    {
        $item = $this->item(800, Unit::Gram);
        $this->service->archive($item, $this->member);

        $this->service->restore($item, $this->member);

        self::assertFalse($item->isArchived());
        self::assertCount(2, $item->getMovements());
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

    private function variant(Unit $unit): HouseholdProductVariant
    {
        $identity = VariantIdentity::of('produit', $unit->family(), []);

        return new HouseholdProductVariant($this->household, 'Produit', $identity, $identity->slugBase());
    }
}
