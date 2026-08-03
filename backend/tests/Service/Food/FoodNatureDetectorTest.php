<?php

declare(strict_types=1);

namespace App\Tests\Service\Food;

use App\Enum\FoodNature;
use App\Enum\ProductTrait;
use App\Enum\Unit;
use App\Service\Food\FoodNatureDetector;
use App\Service\Food\FoodTermMatcher;
use App\Service\Food\FreshFoodLexicon;
use App\Service\Freshness\ShelfLifeEstimator;
use App\Service\TextNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * La nature décide du formulaire de saisie, de l'estimation d'office de la date
 * et du rang dans les recettes. Une erreur ici se voit donc partout.
 *
 * Les cas limites sont écrits avant d'être crus justes (leçon n° 3), et chaque
 * terme du lexique est vérifié aliment par aliment (leçon n° 2).
 */
class FoodNatureDetectorTest extends TestCase
{
    private FoodNatureDetector $detector;
    private FreshFoodLexicon $lexicon;

    protected function setUp(): void
    {
        $normalizer = new TextNormalizer();
        $this->lexicon = new FreshFoodLexicon(new FoodTermMatcher($normalizer));
        $this->detector = new FoodNatureDetector($this->lexicon, $normalizer);
    }

    public function testAVegetableIsFresh(): void
    {
        self::assertSame(FoodNature::Fresh, $this->detector->detect('Courgettes'));
        self::assertSame(FoodNature::Fresh, $this->detector->detect('Pommes de terre nouvelles'));
    }

    /** Une conserve de tomates n'est pas une tomate fraîche : c'est le trait qui tranche. */
    public function testAPreservingTraitWins(): void
    {
        self::assertSame(
            FoodNature::Packaged,
            $this->detector->detect('Tomates pelées', 'Conserves', [ProductTrait::Canned]),
        );
        self::assertSame(
            FoodNature::Packaged,
            $this->detector->detect('Épinards en branches', null, [ProductTrait::Frozen]),
        );
    }

    /**
     * `cooked` n'est PAS un procédé de conservation : un reste de gratin est cuit
     * et frais à la fois — c'est même l'aliment le plus périssable du frigo.
     */
    public function testCookedIsNotPreserved(): void
    {
        self::assertSame(
            FoodNature::Fresh,
            $this->detector->detect('Reste de gratin dauphinois', null, [ProductTrait::Cooked]),
        );
    }

    /**
     * Le piège de la leçon n° 2 : « fraîche » ne doit RIEN déclencher.
     * Une crème fraîche est un pot conditionné avec une date imprimée.
     */
    public function testCremeFraicheStaysPackaged(): void
    {
        self::assertSame(FoodNature::Packaged, $this->detector->detect('Crème fraîche épaisse'));
        self::assertSame(FoodNature::Packaged, $this->detector->detect('Pâtes fraîches aux œufs'));
    }

    /** Lait, yaourts, œufs, beurre : conditionnés, malgré leur courte conservation. */
    public function testDairyAndEggsStayPackaged(): void
    {
        foreach (['Lait demi-écrémé', 'Yaourts nature', 'Œufs bio', 'Beurre doux', 'Jambon blanc'] as $label) {
            self::assertSame(FoodNature::Packaged, $this->detector->detect($label), $label);
        }
    }

    /** Le rayon rattrape un aliment que le lexique ne connaît pas. */
    public function testAnUnknownFoodFromAFreshAisleIsFresh(): void
    {
        self::assertSame(FoodNature::Fresh, $this->detector->detect('Topinambours', 'Légumes frais'));
        self::assertSame(FoodNature::Fresh, $this->detector->detect('Bavette d\'aloyau', 'Viandes'));
    }

    /**
     * Le défaut trouvé sur un vrai inventaire, et la règle qui le corrige.
     *
     * Un double concentré de tomates, une pulpe en dés, une mangue au sirop et des
     * poissons panés étaient tous rangés « frais » parce que leur nom contient un
     * aliment frais. Ils ont tous un code-barres — donc un emballage, donc une date
     * imprimée : il n'y a rien à estimer et rien à traiter comme du frais.
     */
    public function testAScannedProductIsAlwaysPackaged(): void
    {
        foreach ([
            ['Double concentré de tomates', 'Double concentrate tomato paste'],
            ['Pulpe de tomates en dés', 'Canned tomatoes'],
            ['Mangue au sirop', 'Frozen foods'],
            ['Petits poissons panés au fromage fondu', 'Fishes'],
            ['Galettes de pomme de terre', 'Frozen fried potatoes'],
            ['Pommes étoiles', null],
        ] as [$name, $category]) {
            self::assertSame(
                FoodNature::Packaged,
                $this->detector->detect($name, $category, scanned: true),
                $name,
            );
        }
    }

    /**
     * Le pendant du test précédent : sans code-barres, c'est le lexique qui parle.
     * Ce sont les courgettes du marché, le filet de pommes de terre en vrac, la
     * côte de porc du boucher — tout ce qu'on saisit à la main.
     */
    public function testTheSameFoodTypedByHandIsFresh(): void
    {
        self::assertSame(FoodNature::Fresh, $this->detector->detect('Pommes de terre'));
        self::assertSame(FoodNature::Fresh, $this->detector->detect('Côte de porc'));
        self::assertSame(FoodNature::Fresh, $this->detector->detect('Tomates du jardin'));
    }

    /**
     * Une catégorie qui déclare le procédé tranche avant le nom de l'aliment —
     * en français comme en anglais, la taxonomie d'Open Food Facts étant anglaise.
     */
    public function testAPreservedCategoryBeatsTheLexicon(): void
    {
        self::assertSame(FoodNature::Packaged, $this->detector->detect('Haricots verts', 'Légumes surgelés'));
        self::assertSame(FoodNature::Packaged, $this->detector->detect('Petits pois', 'Conserves de légumes'));
        self::assertSame(FoodNature::Packaged, $this->detector->detect('Haricots verts', 'Canned green beans'));
        self::assertSame(FoodNature::Packaged, $this->detector->detect('Poireaux en rondelles', 'Frozen leeks'));
    }

    public function testAnythingUnknownStaysPackaged(): void
    {
        self::assertSame(FoodNature::Packaged, $this->detector->detect('Sirop d\'agave'));
        self::assertSame(FoodNature::Packaged, $this->detector->detect('Machin inconnu', 'Épicerie'));
    }

    /** Une courgette se compte, des champignons se pèsent, un reste se sert en portions. */
    public function testTheLexiconGivesTheNaturalUnit(): void
    {
        self::assertSame(Unit::Piece, $this->lexicon->find('Courgettes')?->naturalUnit);
        self::assertSame(Unit::Gram, $this->lexicon->find('Champignons de Paris')?->naturalUnit);
        self::assertSame(Unit::Portion, $this->lexicon->find('Restes du dimanche')?->naturalUnit);
        self::assertSame(Unit::Milliliter, $this->lexicon->find('Soupe de potiron')?->naturalUnit);
    }

    /**
     * Un plat se nomme par sa forme, pas par son ingrédient : « soupe de potiron »
     * est une soupe. Sans cette règle, `potiron` — plus long — l'emportait, et la
     * soupe se comptait en pièces.
     */
    public function testAPreparedDishBeatsItsIngredient(): void
    {
        self::assertSame('soupe', $this->lexicon->find('Soupe de potiron')?->term);
        self::assertSame(Unit::Milliliter, $this->lexicon->find('Soupe de potiron')?->naturalUnit);

        self::assertSame('gratin', $this->lexicon->find('Gratin de courgettes')?->term);
        self::assertSame(Unit::Portion, $this->lexicon->find('Gratin de courgettes')?->naturalUnit);

        self::assertSame('reste', $this->lexicon->find('Reste de poulet')?->term);
        self::assertSame(FoodNature::Fresh, $this->detector->detect('Reste de poulet'));
    }

    /**
     * Le poids indicatif n'est transmis qu'au générateur de recettes, pour qu'il
     * puisse écrire « environ 500 g » tout en demandant « 2 pièces » au stock.
     */
    public function testCountedFoodsCarryAnIndicativeWeight(): void
    {
        self::assertSame(250, $this->lexicon->find('Courgettes')?->approxPieceWeight);
        self::assertNull($this->lexicon->find('Champignons')?->approxPieceWeight);
    }

    /** Le terme le plus long gagne ici aussi : une pomme de terre se pèse, une pomme se compte. */
    public function testTheLongestTermWins(): void
    {
        self::assertSame('pomme de terre', $this->lexicon->find('Pommes de terre')?->term);
        self::assertSame(Unit::Gram, $this->lexicon->find('Pommes de terre')?->naturalUnit);
        self::assertSame('pomme', $this->lexicon->find('Pommes Golden')?->term);
        self::assertSame(Unit::Piece, $this->lexicon->find('Pommes Golden')?->naturalUnit);
    }

    /**
     * Tout aliment déclaré frais doit avoir une durée de conservation propre :
     * sans elle il n'aurait aucune date estimée, donc resterait invisible — le
     * défaut exact que le traitement du frais existe pour corriger.
     */
    public function testEveryFreshTermHasAShelfLife(): void
    {
        $known = ShelfLifeEstimator::knownTerms();

        foreach (FreshFoodLexicon::terms() as $term) {
            self::assertContains(
                $term,
                $known,
                sprintf('« %s » est déclaré frais mais n\'a aucune durée de conservation.', $term),
            );
        }
    }
}
