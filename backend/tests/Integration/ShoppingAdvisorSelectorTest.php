<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Enum\MealSlot;
use App\Enum\MovementType;
use App\Enum\ShoppingAdviceConfidence;
use App\Enum\ShoppingAdviceKind;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Service\Shopping\ShoppingAdvisorCandidate;
use App\Service\Shopping\ShoppingAdvisorSelector;

/**
 * Le conseiller avant les courses (Évolution 3, `tasks/evolutions.md`) : un
 * scénario par motif, la priorité entre motifs quand plusieurs s'appliquent, et
 * les deux garde-fous explicitement demandés par le document — désactivation
 * par variante, silence quand aucune donnée fiable ne justifie une suggestion.
 */
class ShoppingAdvisorSelectorTest extends DatabaseTestCase
{
    private ShoppingAdvisorSelector $selector;
    private Household $household;
    private HouseholdProductVariant $riz;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ShoppingAdvisorSelector $selector */
        $selector = static::getContainer()->get(ShoppingAdvisorSelector::class);
        $this->selector = $selector;

        $this->household = $this->makeHousehold();
        $this->riz = $this->makeVariant($this->household, 'Riz', Unit::Gram);
    }

    public function testItemExpiringSoonIsFlaggedUseSoonDontAdd(): void
    {
        $this->makeLot($this->household, $this->riz, 500, Unit::Gram, '+1 day');
        $this->entityManager->flush();

        $candidate = $this->candidateFor($this->riz);

        self::assertSame(ShoppingAdviceKind::UseSoonDontAdd, $candidate->kind);
        self::assertSame(ShoppingAdviceConfidence::Certaine, $candidate->confidence);
    }

    public function testStockCoveringAPlannedMealIsFlaggedSufficient(): void
    {
        $this->makeLot($this->household, $this->riz, 1000, Unit::Gram);
        $this->makeMealPlanEntry($this->household, new \DateTimeImmutable('+2 days'), MealSlot::Lunch, [
            ['key' => $this->riz->getSlug(), 'label' => 'Riz', 'quantity' => 200, 'unit' => Unit::Gram],
        ]);
        $this->entityManager->flush();

        $candidate = $this->candidateFor($this->riz);

        self::assertSame(ShoppingAdviceKind::SufficientForPlannedMeals, $candidate->kind);
        self::assertSame(ShoppingAdviceConfidence::Certaine, $candidate->confidence);
    }

    public function testAShortPlannedMealDoesNotCountAsSufficient(): void
    {
        $this->makeLot($this->household, $this->riz, 100, Unit::Gram);
        $this->makeMealPlanEntry($this->household, new \DateTimeImmutable('+2 days'), MealSlot::Lunch, [
            ['key' => $this->riz->getSlug(), 'label' => 'Riz', 'quantity' => 200, 'unit' => Unit::Gram],
        ]);
        $this->entityManager->flush();

        $candidates = $this->selector->select($this->household);

        self::assertSame([], $candidates, 'Un manque sur le repas planifié ne doit jamais être présenté comme suffisant.');
    }

    public function testAbundantStockComparedToUsualPurchaseIsFlaggedDontRebuy(): void
    {
        $this->riz->rememberPurchase(500.0, StorageLocation::Pantry);
        $this->makeLot($this->household, $this->riz, 1500, Unit::Gram);
        $this->entityManager->flush();

        $candidate = $this->candidateFor($this->riz);

        self::assertSame(ShoppingAdviceKind::DontRebuy, $candidate->kind);
        self::assertSame(ShoppingAdviceConfidence::Certaine, $candidate->confidence);
    }

    public function testAbundantStockAtCurrentPaceIsFlaggedDontRebuyByRate(): void
    {
        $item = $this->makeLot($this->household, $this->riz, 5000, Unit::Gram);
        for ($i = 0; $i < 4; ++$i) {
            $this->makeMovement($item, MovementType::Consumption, quantity: -10.0);
        }
        $this->entityManager->flush();

        // Rythme observé : 40 g / 21 jours ≈ 1,9 g/jour → 5 000 g couvrent
        // largement plus que les 10 jours d'autonomie du seuil d'abondance.
        $now = new \DateTimeImmutable('+21 days');
        $candidate = $this->candidateFor($this->riz, $now);

        self::assertSame(ShoppingAdviceKind::DontRebuy, $candidate->kind);
        self::assertSame(ShoppingAdviceConfidence::Elevee, $candidate->confidence);
    }

    public function testLowStockAtCurrentPaceIsFlaggedRunningOut(): void
    {
        $item = $this->makeLot($this->household, $this->riz, 20, Unit::Gram);
        for ($i = 0; $i < 4; ++$i) {
            $this->makeMovement($item, MovementType::Consumption, quantity: -100.0);
        }
        $this->entityManager->flush();

        // Rythme observé : 400 g / 21 jours ≈ 19 g/jour ; 20 g restants ≈ 1 jour.
        $now = new \DateTimeImmutable('+21 days');
        $candidate = $this->candidateFor($this->riz, $now);

        self::assertSame(ShoppingAdviceKind::RunningOut, $candidate->kind);
        self::assertSame(ShoppingAdviceConfidence::Elevee, $candidate->confidence);
    }

    public function testAWeakConsumptionHistoryNeverProducesARunningOutSuggestion(): void
    {
        $item = $this->makeLot($this->household, $this->riz, 20, Unit::Gram);
        // Un seul mouvement : confiance faible, jamais traduite en suggestion —
        // exactement ce que demande tasks/evolutions.md (« une mauvaise prédiction
        // serait plus dommageable qu'une absence de prédiction »).
        $this->makeMovement($item, MovementType::Consumption, quantity: -500.0);
        $this->entityManager->flush();

        $candidates = $this->selector->select($this->household);

        self::assertSame([], $candidates);
    }

    public function testExpiringSoonTakesPriorityOverAbundantStock(): void
    {
        $this->riz->rememberPurchase(200.0, StorageLocation::Pantry);
        $this->makeLot($this->household, $this->riz, 1500, Unit::Gram, '+1 day');
        $this->entityManager->flush();

        $candidate = $this->candidateFor($this->riz);

        self::assertSame(ShoppingAdviceKind::UseSoonDontAdd, $candidate->kind);
    }

    public function testMutedVariantProducesNoSuggestion(): void
    {
        $this->makeLot($this->household, $this->riz, 500, Unit::Gram, '+1 day');
        $this->riz->muteShoppingAdvice(new \DateTimeImmutable());
        $this->entityManager->flush();

        self::assertSame([], $this->selector->select($this->household));
    }

    public function testOrdinaryStockWithNoSignalProducesNoSuggestion(): void
    {
        $this->makeLot($this->household, $this->riz, 500, Unit::Gram);
        $this->entityManager->flush();

        self::assertSame([], $this->selector->select($this->household));
    }

    private function candidateFor(HouseholdProductVariant $variant, ?\DateTimeImmutable $now = null): ShoppingAdvisorCandidate
    {
        foreach ($this->selector->select($this->household, 6, $now) as $candidate) {
            if ((string) $candidate->variant->getId() === (string) $variant->getId()) {
                return $candidate;
            }
        }

        self::fail('Aucune suggestion n\'a été produite pour la variante attendue.');
    }
}
