<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Enum\UnitFamily;
use App\Service\Ai\Import\ImportedLineResolver;
use App\Service\Ai\Import\KnownFood;
use App\Service\TextNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * An imported recipe only deducts what can be tied to a food of the household
 * with certainty. Each wrong tie deducts the wrong lot; each missed one leaves
 * stock that was eaten. Both failures get the same number of tests (lesson 14),
 * and a dropped deduction is always said, never silent.
 */
class ImportedLineResolverTest extends TestCase
{
    private ImportedLineResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ImportedLineResolver(new TextNormalizer());
    }

    // ── Ties that must hold ───────────────────────────────────────────────

    public function testAKeyCopiedFromTheDataIsKept(): void
    {
        $result = $this->resolve([self::proposal('tomate-conserve', 'Tomates (en conserve)', 400, 'g')]);

        self::assertSame([['key' => 'tomate-conserve', 'label' => 'Tomates (en conserve)', 'quantity' => 400.0, 'unit' => 'g']], $result['proposal']);
        self::assertSame([], $result['notices']);
    }

    /** The confirmation dialog prints the label: it must name what will really be deducted. */
    public function testAKnownKeyWithARewordedLabelKeepsTheKeyAndTakesTheHouseholdLabel(): void
    {
        $result = $this->resolve([self::proposal('tomate-conserve', 'tomates concassées en boîte', 400, 'g')]);

        self::assertSame('tomate-conserve', $result['proposal'][0]['key']);
        self::assertSame('Tomates (en conserve)', $result['proposal'][0]['label']);
    }

    /** Out of stock today is not unknown: the plan will say "à acheter", not the importer. */
    public function testAKnownKeyWithNoStockIsKept(): void
    {
        $result = $this->resolve([self::proposal('creme-fraiche', 'Crème fraîche', 20, 'cl')]);

        self::assertSame('creme-fraiche', $result['proposal'][0]['key']);
        self::assertSame([], $result['notices']);
    }

    public function testAMissingKeyIsFoundByTheExactLabelWhateverTheCaseAndAccents(): void
    {
        $result = $this->resolve([self::proposal(null, 'TOMATES (EN CONSERVE)', 400, 'g')]);

        self::assertSame('tomate-conserve', $result['proposal'][0]['key']);
    }

    public function testAPlainLabelGoesToThePlainFoodWhenBothAreInStock(): void
    {
        $result = $this->resolve([self::proposal(null, 'Tomates', 3, 'piece')]);

        self::assertSame('tomate', $result['proposal'][0]['key']);
    }

    public function testKilogramsDeductFromAFoodCountedInGrams(): void
    {
        $result = $this->resolve([self::proposal('tomate-conserve', 'Tomates (en conserve)', 0.4, 'kg')]);

        self::assertSame(['key' => 'tomate-conserve', 'label' => 'Tomates (en conserve)', 'quantity' => 0.4, 'unit' => 'kg'], $result['proposal'][0]);
    }

    public function testAnIngredientLineGetsItsKeyFromTheLabel(): void
    {
        $result = $this->resolve([], [['label' => 'Tomates (en conserve)', 'quantity' => 400, 'unit' => 'g', 'essential' => true]]);

        self::assertSame('tomate-conserve', $result['ingredients'][0]['key']);
    }

    // ── Ties that must not be made ────────────────────────────────────────

    /** "Tomates" is fresh tomatoes; the canned ones being the only ones left changes nothing. */
    public function testAPlainLabelNeverFallsBackOnAPreservedFood(): void
    {
        $known = [
            'tomate-conserve' => new KnownFood('tomate-conserve', 'Tomates (en conserve)', UnitFamily::Mass, true),
        ];

        $result = $this->resolve([self::proposal(null, 'Tomates', 400, 'g')], known: $known);

        self::assertSame([], $result['proposal']);
        self::assertSame(['« Tomates » n\'a pas été reconnu dans votre stock : il ne sera pas retiré.'], $result['notices']);
    }

    public function testTwoFoodsWithTheSameLabelAreNotGuessedBetween(): void
    {
        $known = [
            'riz' => new KnownFood('riz', 'Riz', UnitFamily::Mass, true),
            'riz-2' => new KnownFood('riz-2', 'Riz', UnitFamily::Mass, true),
        ];

        $result = $this->resolve([self::proposal(null, 'Riz', 150, 'g')], known: $known);

        self::assertSame([], $result['proposal']);
        self::assertCount(1, $result['notices']);
    }

    /** The key says fresh, the label names the canned ones in stock: which one was eaten is unknown. */
    public function testAKeyAndALabelNamingTwoDifferentFoodsAreNotDeducted(): void
    {
        $result = $this->resolve([self::proposal('tomate', 'Tomates (en conserve)', 400, 'g')]);

        self::assertSame([], $result['proposal']);
        self::assertSame(
            ['« Tomates (en conserve) » : sa clé désigne « Tomates », un autre aliment de votre stock. Il ne sera pas retiré.'],
            $result['notices'],
        );
    }

    public function testAnInventedKeyWithAnUnknownLabelIsDropped(): void
    {
        $result = $this->resolve([self::proposal('poulet', 'Blanc de poulet', 300, 'g')]);

        self::assertSame([], $result['proposal']);
        self::assertSame(['« Blanc de poulet » n\'a pas été reconnu dans votre stock : il ne sera pas retiré.'], $result['notices']);
    }

    /** Otherwise "Retirer du stock" fails for the whole recipe, months later. */
    public function testGramsAreNotDeductedFromAFoodCountedByThePiece(): void
    {
        $result = $this->resolve([self::proposal('tomate', 'Tomates', 300, 'g')]);

        self::assertSame([], $result['proposal']);
        self::assertSame(['« Tomates » se compte à l\'unité dans votre stock, pas en g : il ne sera pas retiré.'], $result['notices']);
    }

    public function testAnUnknownUnitIsDroppedAndSaid(): void
    {
        $result = $this->resolve([self::proposal('tomate-conserve', 'Tomates (en conserve)', 400, 'grammes')]);

        self::assertSame([], $result['proposal']);
        self::assertSame(['« Tomates (en conserve) » : l\'unité « grammes » n\'est pas reconnue, il ne sera pas retiré.'], $result['notices']);
    }

    public function testAQuantityThatIsNotAPositiveNumberIsDroppedAndSaid(): void
    {
        $result = $this->resolve([
            self::proposal('tomate-conserve', 'Tomates (en conserve)', '200 g', 'g'),
            self::proposal('tomate-conserve', 'Tomates (en conserve)', 0, 'g'),
        ]);

        self::assertSame([], $result['proposal']);
        self::assertSame(array_fill(0, 2, '« Tomates (en conserve) » : quantité illisible, il ne sera pas retiré.'), $result['notices']);
    }

    /** An ingredient line only loses its link to the lot: nothing to deduct, nothing to say. */
    public function testAnUnknownIngredientLosesItsKeySilently(): void
    {
        $result = $this->resolve([], [['key' => 'poulet', 'label' => 'Blanc de poulet', 'quantity' => 300, 'unit' => 'g', 'essential' => true]]);

        self::assertSame([['label' => 'Blanc de poulet', 'quantity' => 300, 'unit' => 'g', 'essential' => true]], $result['ingredients']);
        self::assertSame([], $result['notices']);
    }

    /** @return array<string, KnownFood> */
    private static function household(): array
    {
        return [
            'tomate' => new KnownFood('tomate', 'Tomates', UnitFamily::Count, true),
            'tomate-conserve' => new KnownFood('tomate-conserve', 'Tomates (en conserve)', UnitFamily::Mass, true),
            'creme-fraiche' => new KnownFood('creme-fraiche', 'Crème fraîche', UnitFamily::Volume, false),
        ];
    }

    /** @return array<string, mixed> */
    private static function proposal(?string $key, string $label, mixed $quantity, string $unit): array
    {
        return array_filter(
            ['key' => $key, 'label' => $label, 'quantity' => $quantity, 'unit' => $unit],
            static fn ($value) => null !== $value,
        );
    }

    /**
     * @param list<array<string, mixed>>   $proposal
     * @param list<array<string, mixed>>   $ingredients
     * @param array<string, KnownFood>|null $known
     *
     * @return array{proposal: list<array<string, mixed>>, ingredients: list<array<string, mixed>>, notices: list<string>}
     */
    private function resolve(array $proposal, array $ingredients = [], ?array $known = null): array
    {
        $result = $this->resolver->resolve(
            ['recipeName' => 'Essai', 'ingredientsFromInventory' => $ingredients, 'stockConsumptionProposal' => $proposal],
            $known ?? self::household(),
        );

        return [
            'proposal' => $result['recipe']['stockConsumptionProposal'],
            'ingredients' => $result['recipe']['ingredientsFromInventory'],
            'notices' => $result['notices'],
        ];
    }
}
