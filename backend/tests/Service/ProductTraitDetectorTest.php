<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\ProductTrait;
use App\Service\ProductTraitDetector;
use App\Service\TextNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Un faux positif ici égare le générateur de recettes aussi sûrement qu'une
 * absence d'information : chaque cas limite connu a son test.
 */
class ProductTraitDetectorTest extends TestCase
{
    private ProductTraitDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new ProductTraitDetector(new TextNormalizer());
    }

    /** Le cas qui a motivé la fonctionnalité : « Épluchez les pommes de terre » sur une conserve. */
    public function testCannedFoodIsAlsoConsideredCooked(): void
    {
        $traits = $this->detector->detect('Pommes de terre en conserve');

        self::assertContains(ProductTrait::Canned, $traits);
        self::assertContains(ProductTrait::Precooked, $traits, 'L\'appertisation cuit son contenu.');
    }

    public function testExplicitPreparationIsRecognised(): void
    {
        self::assertContains(ProductTrait::Peeled, $this->detector->detect('Pommes de terre épluchées'));
        self::assertContains(ProductTrait::Sliced, $this->detector->detect('Emmental râpé'));
        self::assertContains(ProductTrait::Frozen, $this->detector->detect('Haricots verts surgelés'));
        self::assertContains(ProductTrait::Dried, $this->detector->detect('Abricots séchés'));
    }

    /** « biscuit » contient « cuit » : sans frontière de mot, tout gâteau serait « déjà cuit ». */
    public function testWordBoundariesAvoidFalsePositives(): void
    {
        self::assertNotContains(ProductTrait::Cooked, $this->detector->detect('Biscuits sablés'));
        self::assertSame([], $this->detector->detect('Tomates'), 'Un nom neutre ne décrit aucun état.');
    }

    /** Une « boîte de 6 œufs » n'est pas une conserve, et un paquet de sucre n'est pas sucré. */
    public function testAmbiguousPackagingWordsAreNotTraits(): void
    {
        self::assertNotContains(ProductTrait::Canned, $this->detector->detect('Œufs frais, boîte de 6'));
        self::assertNotContains(ProductTrait::Sweetened, $this->detector->detect('Sucre en poudre'));
    }

    /** « précuit » contient « cuit » : garder les deux brouillerait la consigne envoyée au modèle. */
    public function testPrecookedSupersedesCooked(): void
    {
        $traits = $this->detector->detect('Lentilles précuites');

        self::assertContains(ProductTrait::Precooked, $traits);
        self::assertNotContains(ProductTrait::Cooked, $traits);
    }

    /** « sans sucres ajoutés » contient « sucre » : la mention négative doit l'emporter. */
    public function testNegativeMentionWins(): void
    {
        $traits = $this->detector->detect('Compote de pommes sans sucres ajoutés');

        self::assertContains(ProductTrait::Unsweetened, $traits);
        self::assertNotContains(ProductTrait::Sweetened, $traits);
    }

    public function testCategoryCompletesTheName(): void
    {
        $traits = $this->detector->detect('Ratatouille', 'Plats cuisinés');

        self::assertContains(ProductTrait::Cooked, $traits);
    }
}
