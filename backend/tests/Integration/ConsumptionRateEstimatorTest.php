<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Enum\ConsumptionConfidence;
use App\Enum\MovementType;
use App\Enum\Unit;
use App\Service\Shopping\ConsumptionRateEstimate;
use App\Service\Shopping\ConsumptionRateEstimator;

/**
 * Le seul mécanisme prédictif de l'Évolution 3 (`tasks/evolutions.md`) : un débit
 * moyen borné à 90 jours, jamais un modèle. `createdAt` n'est modifiable par
 * aucune API publique (voir `StockReviewQueueTest`) : les mouvements sont créés
 * « maintenant » et `$now` est avancé dans le futur pour simuler l'écoulement.
 */
class ConsumptionRateEstimatorTest extends DatabaseTestCase
{
    private ConsumptionRateEstimator $estimator;
    private Household $household;
    private HouseholdProductVariant $riz;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ConsumptionRateEstimator $estimator */
        $estimator = static::getContainer()->get(ConsumptionRateEstimator::class);
        $this->estimator = $estimator;

        $this->household = $this->makeHousehold();
        $this->riz = $this->makeVariant($this->household, 'Riz', Unit::Gram);
    }

    public function testNoMovementProducesNoEstimate(): void
    {
        $this->makeLot($this->household, $this->riz, 1000, Unit::Gram);
        $this->entityManager->flush();

        self::assertArrayNotHasKey((string) $this->riz->getId(), $this->estimator->estimate($this->household));
    }

    public function testASingleMovementIsLowConfidence(): void
    {
        $item = $this->makeLot($this->household, $this->riz, 1000, Unit::Gram);
        $this->makeMovement($item, MovementType::Consumption, quantity: -100.0);
        $this->entityManager->flush();

        self::assertSame(ConsumptionConfidence::Low, $this->estimateFor($this->riz)->confidence);
    }

    public function testFourMovementsSpreadOverThreeWeeksAreHighConfidence(): void
    {
        $item = $this->makeLot($this->household, $this->riz, 1000, Unit::Gram);
        for ($i = 0; $i < 4; ++$i) {
            $this->makeMovement($item, MovementType::Consumption, quantity: -100.0);
        }
        $this->entityManager->flush();

        $now = new \DateTimeImmutable('+21 days');
        $estimate = $this->estimateFor($this->riz, $now);

        self::assertSame(ConsumptionConfidence::High, $estimate->confidence);
        self::assertSame(4, $estimate->sampleCount);
        self::assertEqualsWithDelta(400.0 / 21, $estimate->dailyRateBase, 0.05);
    }

    public function testTwoMovementsOverAWeekAreMediumConfidence(): void
    {
        $item = $this->makeLot($this->household, $this->riz, 1000, Unit::Gram);
        $this->makeMovement($item, MovementType::Consumption, quantity: -100.0);
        $this->makeMovement($item, MovementType::Consumption, quantity: -100.0);
        $this->entityManager->flush();

        $now = new \DateTimeImmutable('+7 days');

        self::assertSame(ConsumptionConfidence::Medium, $this->estimateFor($this->riz, $now)->confidence);
    }

    public function testDiscardedStockNeverCountsAsConsumption(): void
    {
        $item = $this->makeLot($this->household, $this->riz, 1000, Unit::Gram);
        $this->makeMovement($item, MovementType::Deletion, quantity: -1000.0);
        $this->entityManager->flush();

        self::assertArrayNotHasKey((string) $this->riz->getId(), $this->estimator->estimate($this->household));
    }

    public function testFreezingNeverCountsAsConsumption(): void
    {
        $item = $this->makeLot($this->household, $this->riz, 1000, Unit::Gram);
        $this->makeMovement($item, MovementType::Correction, quantity: 0.0);
        $this->entityManager->flush();

        self::assertArrayNotHasKey((string) $this->riz->getId(), $this->estimator->estimate($this->household));
    }

    public function testMovementsOlderThanTheLookbackWindowAreIgnored(): void
    {
        $item = $this->makeLot($this->household, $this->riz, 1000, Unit::Gram);
        $this->makeMovement($item, MovementType::Consumption, quantity: -100.0);
        $this->entityManager->flush();

        // La fenêtre est bornée à 90 jours : au-delà, le mouvement ne compte plus.
        $now = new \DateTimeImmutable('+91 days');

        self::assertArrayNotHasKey((string) $this->riz->getId(), $this->estimator->estimate($this->household, $now));
    }

    private function estimateFor(HouseholdProductVariant $variant, ?\DateTimeImmutable $now = null): ConsumptionRateEstimate
    {
        $estimates = $this->estimator->estimate($this->household, $now);
        $id = (string) $variant->getId();

        self::assertArrayHasKey($id, $estimates, 'Aucune estimation pour la variante attendue.');

        return $estimates[$id];
    }
}
