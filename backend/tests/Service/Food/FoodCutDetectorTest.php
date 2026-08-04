<?php

declare(strict_types=1);

namespace App\Tests\Service\Food;

use App\Enum\FoodCut;
use App\Service\Food\FoodCutDetector;
use App\Service\Food\FoodCutLexicon;
use App\Service\Food\FoodTermMatcher;
use App\Service\TextNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * La découpe décide de la cuisson : s'y tromper produit exactement le défaut qui
 * a motivé cet axe — une recette d'escalopes écrite sur du blanc de poulet en
 * tranches. Un faux positif y coûte donc plus cher qu'une absence de réponse,
 * d'où un détecteur volontairement muet dès que le libellé ne nomme pas la forme.
 */
class FoodCutDetectorTest extends TestCase
{
    private FoodCutDetector $detector;

    protected function setUp(): void
    {
        $normalizer = new TextNormalizer();

        $this->detector = new FoodCutDetector(
            $normalizer,
            new FoodCutLexicon(new FoodTermMatcher($normalizer)),
        );
    }

    /** Le cas qui a motivé la fonctionnalité : les deux libellés ne doivent PAS se rejoindre. */
    public function testSlicesAndEscalopeAreNeverConfused(): void
    {
        self::assertSame(FoodCut::Escalope, $this->detector->detect('Escalopes de poulet'));
        self::assertSame(FoodCut::Slices, $this->detector->detect('Blanc de poulet en tranches'));
    }

    public function testCommonButcheryFormsAreRecognised(): void
    {
        self::assertSame(FoodCut::Strips, $this->detector->detect('Émincé de bœuf'));
        self::assertSame(FoodCut::Strips, $this->detector->detect('Aiguillettes de canard'));
        self::assertSame(FoodCut::Ground, $this->detector->detect('Steak haché 5 % MG'));
        self::assertSame(FoodCut::BoneIn, $this->detector->detect('Pilons de poulet'));
        self::assertSame(FoodCut::Steak, $this->detector->detect('Pavé de saumon'));
        self::assertSame(FoodCut::Cubes, $this->detector->detect('Bœuf en dés'));
    }

    public function testVegetableAndDairyFormsAreRecognised(): void
    {
        self::assertSame(FoodCut::Grated, $this->detector->detect('Emmental râpé'));
        self::assertSame(FoodCut::Slices, $this->detector->detect('Carottes en rondelles'));
        self::assertSame(FoodCut::Puree, $this->detector->detect('Pulpe de tomates'));
        self::assertSame(FoodCut::Pieces, $this->detector->detect('Tomates concassées'));
        self::assertSame(FoodCut::Whole, $this->detector->detect('Poulet entier fermier'));
    }

    /**
     * « steak » seul est un format de plat, pas une découpe : un steak de soja est
     * une galette, un steak de bœuf est un pavé. Seul « steak haché » est haché.
     */
    public function testSteakAloneIsNotGround(): void
    {
        self::assertNotSame(FoodCut::Ground, $this->detector->detect('Steak de soja'));
        self::assertSame(FoodCut::Ground, $this->detector->detect('Steaks hachés de bœuf'));
    }

    /** « tranche napolitaine » est une glace, « pavé » un fromage : le mot seul ne suffit pas. */
    public function testAmbiguousFormWordsAreNotCuts(): void
    {
        self::assertNull($this->detector->detect('Tranche napolitaine vanille fraise'));
        self::assertNull($this->detector->detect('Pavé d\'Affinois'));
    }

    /** Un libellé qui ne nomme aucune forme ne doit rien produire : le doute reste le doute. */
    public function testSilentWhenTheLabelSaysNothing(): void
    {
        self::assertNull($this->detector->detect('Blanc de poulet'));
        self::assertNull($this->detector->detect('Tomates'));
        self::assertNull($this->detector->detect('Farine de blé T55'));
        self::assertNull($this->detector->detect('Lait demi-écrémé'));
    }

    /**
     * Les catégories arrivent d'Open Food Facts dans leur taxonomie anglaise
     * (leçon nº 31) : une liste purement française ne reconnaîtrait jamais rien.
     */
    public function testEnglishCategoryTermsAreRecognised(): void
    {
        self::assertSame(FoodCut::Grated, $this->detector->detect('Mozzarella', 'Grated cheeses'));
        self::assertSame(FoodCut::Ground, $this->detector->detect('Bœuf', 'Ground meat'));
        self::assertSame(FoodCut::Slices, $this->detector->detect('Jambon', 'Sliced ham'));
    }

    /**
     * « émincer » est un verbe d'étape, pas une forme reçue : il ne doit pas
     * traverser depuis une catégorie ni depuis un nom de plat cuisiné.
     */
    public function testVerbsDoNotDescribeTheFoodReceived(): void
    {
        self::assertNull($this->detector->detect('Kit pour émincer les légumes'));
    }

    /**
     * Les trois cas trouvés en passant la règle sur le vrai inventaire, qu'aucun
     * exemple choisi n'avait vus (leçon n° 30).
     *
     * Un oignon émincé est la découpe la plus courante de la cuisine française, et
     * `Strips` manquait aux légumes — l'oignon retombait sur « en morceaux ».
     */
    public function testMincedVegetablesAreStrips(): void
    {
        self::assertSame(FoodCut::Strips, $this->detector->detect('Oignons émincés'));
        self::assertSame(FoodCut::Strips, $this->detector->detect('Poireaux émincés surgelés'));
    }

    /**
     * « Pulpe de tomates EN DÉS » nomme une famille de produit, puis sa découpe :
     * c'est la seconde qui décrit ce qu'on trouvera dans la boîte.
     */
    public function testAnExplicitShapeBeatsTheProductFamily(): void
    {
        self::assertSame(FoodCut::Cubes, $this->detector->detect('Pulpe de tomates en dés'));
        self::assertSame(FoodCut::Puree, $this->detector->detect('Pulpe de tomates'));
    }
}
