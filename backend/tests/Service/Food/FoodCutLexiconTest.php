<?php

declare(strict_types=1);

namespace App\Tests\Service\Food;

use App\Enum\FoodCut;
use App\Service\Food\FoodCutLexicon;
use App\Service\Food\FoodTermMatcher;
use App\Service\TextNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Ce lexique répond à deux questions d'un coup : « la découpe change-t-elle
 * quelque chose pour cet aliment ? » et « lesquelles proposer ? ». C'est ce qui
 * évite de demander à l'utilisateur la découpe de sa farine, et de lui présenter
 * onze puces quand quatre suffisent.
 */
class FoodCutLexiconTest extends TestCase
{
    private FoodCutLexicon $lexicon;

    protected function setUp(): void
    {
        $normalizer = new TextNormalizer();
        $this->lexicon = new FoodCutLexicon(new FoodTermMatcher($normalizer));
    }

    public function testMeatOffersButcheryForms(): void
    {
        $cuts = $this->lexicon->plausibleCuts('Blanc de poulet');

        self::assertNotNull($cuts);
        self::assertContains(FoodCut::Escalope, $cuts);
        self::assertContains(FoodCut::Strips, $cuts);
        self::assertContains(FoodCut::Slices, $cuts, 'Le cas qui a motivé cet axe doit être proposable.');
        self::assertNotContains(FoodCut::Grated, $cuts, 'On ne râpe pas un blanc de poulet.');
    }

    public function testCheeseOffersGratedButNotButcheryForms(): void
    {
        $cuts = $this->lexicon->plausibleCuts('Emmental');

        self::assertNotNull($cuts);
        self::assertContains(FoodCut::Grated, $cuts);
        self::assertNotContains(FoodCut::Ground, $cuts);
        self::assertNotContains(FoodCut::BoneIn, $cuts);
    }

    /** La question ne se pose pas pour tout le monde : le silence est une réponse. */
    public function testFoodsWhoseFormNeverMattersAreSilent(): void
    {
        self::assertNull($this->lexicon->plausibleCuts('Farine de blé T55'));
        self::assertNull($this->lexicon->plausibleCuts('Lait demi-écrémé'));
        self::assertNull($this->lexicon->plausibleCuts('Riz basmati'));
        self::assertNull($this->lexicon->plausibleCuts('Huile d\'olive vierge extra'));
    }

    /** « pomme de terre » est un légume, « pomme » un fruit : le terme le plus long gagne. */
    public function testLongestTermWins(): void
    {
        self::assertContains(FoodCut::Grated, (array) $this->lexicon->plausibleCuts('Pommes de terre'));
        self::assertNotContains(FoodCut::Grated, (array) $this->lexicon->plausibleCuts('Pommes Golden'));
    }

    /**
     * « Soupe de potiron » est une soupe : on ne demande pas en quels dés elle est
     * coupée. Reconnue et sans découpe (`[]`), elle se distingue d'un aliment
     * inconnu (`null`) — c'est ce qui permet au détecteur de s'y arrêter net.
     */
    public function testPreparedDishesAreRecognisedAsHavingNoCut(): void
    {
        self::assertSame([], $this->lexicon->plausibleCuts('Soupe de potiron'));
        self::assertSame([], $this->lexicon->plausibleCuts('Reste de poulet rôti'));
        self::assertSame([], $this->lexicon->plausibleCuts('Gratin de pommes de terre'));
    }

    public function testBreadOnlyOffersWholeOrSlices(): void
    {
        self::assertSame([FoodCut::Whole, FoodCut::Slices], $this->lexicon->plausibleCuts('Pain de mie complet'));
    }
}
