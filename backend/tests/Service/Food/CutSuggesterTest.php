<?php

declare(strict_types=1);

namespace App\Tests\Service\Food;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Enum\DataOrigin;
use App\Enum\FoodCut;
use App\Enum\FoodNature;
use App\Enum\ProductTrait;
use App\Enum\UnitFamily;
use App\Repository\HouseholdProductVariantRepository;
use App\Service\Food\CutSuggester;
use App\Service\Food\CutSuggestion;
use App\Service\Food\FoodCutDetector;
use App\Service\Food\FoodCutLexicon;
use App\Service\Food\FoodTermMatcher;
use App\Service\IngredientKeyGenerator;
use App\Service\Product\VariantIdentity;
use App\Service\TextNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * The cut a new purchase gets when nobody chose one. A wrong cut misleads the
 * cooking more than a missing one, so each step answers only when it knows:
 * the label's own words, then what this household confirmed for the same
 * food, then "whole" for fresh produce — and nothing otherwise.
 */
class CutSuggesterTest extends TestCase
{
    private Household $household;
    /** @var list<HouseholdProductVariant> */
    private array $known = [];

    protected function setUp(): void
    {
        $this->household = new Household('Foyer de test');
    }

    public function testTheLabelsOwnWordsWinOverMemory(): void
    {
        $this->remember('Escalopes de poulet', FoodCut::Strips);

        $suggestion = $this->suggest('Escalopes de poulet');

        self::assertSame(FoodCut::Escalope, $suggestion->cut);
        self::assertSame(CutSuggestion::SOURCE_LABEL, $suggestion->source);
    }

    /** "Blanc de poulet" set to escalopes once stays escalopes on the next purchase. */
    public function testTheHouseholdsConfirmedCutIsRemembered(): void
    {
        $this->remember('Blanc de poulet', FoodCut::Escalope);

        $suggestion = $this->suggest('Blanc de poulet');

        self::assertSame(FoodCut::Escalope, $suggestion->cut);
        self::assertSame(CutSuggestion::SOURCE_HOUSEHOLD, $suggestion->source);
    }

    /** Two confirmed cuts for the same food: guessing between them would be a coin toss. */
    public function testTwoConfirmedCutsAreAmbiguous(): void
    {
        $this->remember('Blanc de poulet', FoodCut::Escalope);
        $this->remember('Blanc de poulet', FoodCut::Strips);

        self::assertNull($this->suggest('Blanc de poulet')->cut);
    }

    /** A cut nobody confirmed is not a habit. */
    public function testADetectedCutIsNotRemembered(): void
    {
        $this->remember('Blanc de poulet', FoodCut::Escalope, DataOrigin::Detected);

        self::assertNull($this->suggest('Blanc de poulet')->cut);
    }

    /** Frozen chicken breast and fresh chicken breast are bought in different forms. */
    public function testMemoryOnlyCountsTheSameState(): void
    {
        $this->remember('Blanc de poulet', FoodCut::Strips, traits: [ProductTrait::Frozen]);

        self::assertNull($this->suggest('Blanc de poulet')->cut);
        self::assertSame(FoodCut::Strips, $this->suggest('Blanc de poulet', traits: [ProductTrait::Frozen])->cut);
    }

    /** Carrots from the market are whole: nobody buys them pre-cut without saying so. */
    public function testFreshProduceIsWhole(): void
    {
        $suggestion = $this->suggest('Carottes', FoodNature::Fresh);

        self::assertSame(FoodCut::Whole, $suggestion->cut);
        self::assertSame(CutSuggestion::SOURCE_PRODUCE, $suggestion->source);
        self::assertSame(FoodCut::Whole, $this->suggest('Pommes', FoodNature::Fresh)->cut);
    }

    public function testMemoryWinsOverTheProduceDefault(): void
    {
        $this->remember('Carottes', FoodCut::Grated);

        self::assertSame(FoodCut::Grated, $this->suggest('Carottes', FoodNature::Fresh)->cut);
    }

    /** Packaged or frozen vegetables are usually cut: the default must not apply. */
    public function testPackagedProduceGetsNoDefault(): void
    {
        self::assertNull($this->suggest('Champignons de Paris', FoodNature::Packaged)->cut);
        self::assertNull($this->suggest('Carottes', FoodNature::Packaged, [ProductTrait::Frozen])->cut);
    }

    /** Fresh meat is exactly the ambiguous case: breast, escalopes, strips are all sold raw. */
    public function testFreshMeatGetsNoDefault(): void
    {
        self::assertNull($this->suggest('Blanc de poulet', FoodNature::Fresh)->cut);
        self::assertNull($this->suggest('Filet de cabillaud', FoodNature::Fresh)->cut);
    }

    public function testTheLabelStillNamesACutOnFreshProduce(): void
    {
        self::assertSame(FoodCut::Grated, $this->suggest('Carottes râpées', FoodNature::Fresh)->cut);
    }

    public function testFoodsWithoutCutsGetNeitherCutsNorSuggestion(): void
    {
        $flour = $this->suggest('Farine de blé');
        self::assertNull($flour->cut);
        self::assertSame([], $flour->plausible);

        $soup = $this->suggest('Soupe de potiron', FoodNature::Fresh);
        self::assertNull($soup->cut, 'A dish has no cut, even when it is fresh.');
        self::assertSame([], $soup->plausible);
    }

    /**
     * The question is asked only where the cut changes the cooking technique:
     * meat and fish. Elsewhere a wrong cut costs one extra "cut it" step, and
     * on the real stock asking everywhere meant asking about "Pommes
     * noisettes" (an apple?) and "Macaroni - Tomate Mozza" (a tomato?).
     */
    public function testTheCutMattersForMeatAndFishOnly(): void
    {
        self::assertTrue($this->suggest('Blanc de poulet')->matters);
        self::assertTrue($this->suggest('Filet de cabillaud')->matters);
        self::assertTrue($this->suggest('Escalopes de poulet')->matters, 'Known or not, it is the kind of food that matters.');

        self::assertFalse($this->suggest('Pommes noisettes')->matters);
        self::assertFalse($this->suggest('Macaroni - Tomate Mozza')->matters);
        self::assertFalse($this->suggest('Jambon blanc')->matters);
        self::assertFalse($this->suggest('Fromage pour tartiflette')->matters);
        self::assertFalse($this->suggest('Carottes', FoodNature::Fresh)->matters);
        self::assertFalse($this->suggest('Farine de blé')->matters);
        self::assertFalse($this->suggest('Soupe de poulet')->matters, 'A dish, even with chicken in it.');
    }

    public function testThePlausibleCutsComeWithTheSuggestion(): void
    {
        $suggestion = $this->suggest('Blanc de poulet');

        self::assertContains(FoodCut::Escalope, $suggestion->plausible);
        self::assertContains(FoodCut::Strips, $suggestion->plausible);
    }

    /** @param list<ProductTrait> $traits */
    private function suggest(
        string $label,
        FoodNature $nature = FoodNature::Packaged,
        array $traits = [],
        ?string $category = null,
    ): CutSuggestion {
        $normalizer = new TextNormalizer();
        $lexicon = new FoodCutLexicon(new FoodTermMatcher($normalizer));
        $keys = new IngredientKeyGenerator($normalizer);

        $repository = $this->createMock(HouseholdProductVariantRepository::class);
        $repository->method('findByIngredientKey')->willReturnCallback(
            fn (Household $household, string $key): array => array_values(array_filter(
                $this->known,
                static fn (HouseholdProductVariant $variant): bool => $variant->getIngredientKey() === $key,
            )),
        );

        return (new CutSuggester(new FoodCutDetector($normalizer, $lexicon), $lexicon, $keys, $repository))
            ->suggest($this->household, $label, $category, $traits, $nature);
    }

    /** @param list<ProductTrait> $traits */
    private function remember(string $label, FoodCut $cut, DataOrigin $origin = DataOrigin::User, array $traits = []): void
    {
        $key = (new IngredientKeyGenerator(new TextNormalizer()))->generate($label);
        $identity = VariantIdentity::of($key, UnitFamily::Mass, $traits, $cut);
        $variant = new HouseholdProductVariant($this->household, $label, $identity, $identity->slugBase());
        $variant->setCutOrigin($origin);

        $this->known[] = $variant;
    }
}
