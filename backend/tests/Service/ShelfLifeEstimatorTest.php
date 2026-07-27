<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\ProductTrait;
use App\Enum\StorageLocation;
use App\Service\Freshness\ShelfLifeEstimator;
use App\Service\TextNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Les fruits et légumes frais n'ont pas de date imprimée.
 *
 * Sans estimation, ils entraient sans date : aucune alerte, aucune priorité, et
 * ils pourrissaient dans le bac à légumes — le gaspillage que l'application
 * existe pour éviter. L'estimation doit rester courte : se tromper court fait
 * vérifier un aliment encore bon, se tromper long fait manger un aliment perdu.
 */
class ShelfLifeEstimatorTest extends TestCase
{
    private ShelfLifeEstimator $estimator;

    protected function setUp(): void
    {
        $this->estimator = new ShelfLifeEstimator(new TextNormalizer());
    }

    public function testFreshProduceGetsAShortShelfLife(): void
    {
        $estimate = $this->estimator->estimate('Salade', null, [], StorageLocation::Fridge);

        self::assertSame(4, $estimate->days);
        self::assertSame('food', $estimate->source);
        self::assertSame('salade', $estimate->matched);
    }

    public function testTheStorageLocationChangesTheDuration(): void
    {
        self::assertSame(30, $this->estimator->estimate('Pommes de terre', null, [], StorageLocation::Pantry)->days);
        self::assertSame(21, $this->estimator->estimate('Pommes de terre', null, [], StorageLocation::Fridge)->days);
    }

    /** « pomme de terre » n'est pas une « pomme » : le terme le plus long gagne. */
    public function testTheLongestMatchingTermWins(): void
    {
        $potato = $this->estimator->estimate('Pommes de terre', null, [], StorageLocation::Pantry);
        $apple = $this->estimator->estimate('Pommes', null, [], StorageLocation::Pantry);

        self::assertSame('pomme de terre', $potato->matched);
        self::assertSame('pomme', $apple->matched);
        self::assertNotSame($potato->days, $apple->days);
    }

    /** Le pluriel doit être reconnu : les libellés d'inventaire le sont presque toujours. */
    public function testPluralsAreRecognised(): void
    {
        self::assertSame('tomate', $this->estimator->estimate('Tomates cerises', null, [], StorageLocation::Fridge)->matched);
        self::assertSame('oeuf', $this->estimator->estimate('Œufs bio', null, [], StorageLocation::Fridge)->matched);
    }

    /**
     * « pâtes » et « pâté » se normalisent tous deux en `pate`.
     * Une tolérance de pluriel donnerait un an de conservation à une terrine.
     */
    public function testPastaAndPateAreNotConfused(): void
    {
        $pasta = $this->estimator->estimate('Pâtes penne', null, [], StorageLocation::Pantry);
        $pate = $this->estimator->estimate('Pâté de campagne', null, [], StorageLocation::Fridge);

        self::assertSame(365, $pasta->days);
        self::assertSame(4, $pate->days);
    }

    /** Le congélateur normalise tout : le légume précis n'a plus d'importance. */
    public function testFreezerOverridesTheFoodTable(): void
    {
        $estimate = $this->estimator->estimate('Salade', null, [], StorageLocation::Freezer);

        self::assertSame(180, $estimate->days);
        self::assertSame('location', $estimate->source);
    }

    public function testTraitsTakeOverWhenTheFoodIsUnknown(): void
    {
        $estimate = $this->estimator->estimate(
            'Ratatouille du chef',
            null,
            [ProductTrait::Canned],
            StorageLocation::Pantry,
        );

        self::assertSame(730, $estimate->days);
        self::assertSame('trait', $estimate->source);
    }

    public function testTheCategoryIsTheNextFallback(): void
    {
        $estimate = $this->estimator->estimate('Machin inconnu', 'Légumes frais', [], StorageLocation::Fridge);

        self::assertSame('category', $estimate->source);
        self::assertSame(7, $estimate->days);
    }

    public function testAnUnknownFoodFallsBackToTheLocation(): void
    {
        $estimate = $this->estimator->estimate('Machin inconnu', null, [], StorageLocation::Fridge);

        self::assertSame('location', $estimate->source);
        self::assertSame(5, $estimate->days);
        self::assertNull($estimate->matched);
    }

    /**
     * Ranger un aliment ailleurs que là où on l'attend ne doit pas allonger sa
     * conservation par accident : à défaut de valeur pour l'emplacement demandé,
     * on prend la plus courte que l'aliment déclare.
     */
    public function testAnUndeclaredLocationTakesTheShortestKnownDuration(): void
    {
        // La salade déclare frigo (4) et placard (1) ; rien pour un autre rangement.
        $estimate = $this->estimator->estimate('Salade', null, [], StorageLocation::Pantry);

        self::assertSame(1, $estimate->days);
    }

    public function testTheEstimateExplainsItself(): void
    {
        $estimate = $this->estimator->estimate('Salade', null, [], StorageLocation::Fridge);

        self::assertStringContainsString('salade', $estimate->explanation());
        self::assertStringContainsString('réfrigérateur', $estimate->explanation());
        self::assertSame(
            (new \DateTimeImmutable('today +4 days'))->format('Y-m-d'),
            $estimate->until()->format('Y-m-d'),
        );
    }

    /** Une durée avant ouverture n'a aucun rapport avec la durée après : le lait UHT en est l'exemple. */
    public function testAfterOpeningDurationIsKnownForSomeFoods(): void
    {
        $estimate = $this->estimator->estimateAfterOpening('Lait UHT', null, []);

        self::assertNotNull($estimate);
        self::assertSame(4, $estimate->days);
        self::assertSame('lait', $estimate->matched);
    }

    /**
     * Contrairement à `estimate()`, il n'y a pas de repli sur l'emplacement :
     * ne pas savoir combien de temps un aliment se garde après ouverture
     * n'autorise pas à inventer une réponse.
     */
    public function testAfterOpeningDurationIsNullRatherThanInvented(): void
    {
        self::assertNull($this->estimator->estimateAfterOpening('Machin inconnu', null, []));
        self::assertNull($this->estimator->estimateAfterOpening('Pommes de terre', null, []));
    }

    public function testAfterOpeningDurationFallsBackToTraitsWhenTheFoodIsUnknown(): void
    {
        $estimate = $this->estimator->estimateAfterOpening('Ratatouille du chef', null, [ProductTrait::Canned]);

        self::assertNotNull($estimate);
        self::assertSame(3, $estimate->days);
        self::assertSame('trait', $estimate->source);
    }
}
