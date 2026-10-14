<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Enum\FoodCut;
use App\Enum\Unit;
use App\Service\Ai\AssumedCutFilter;
use App\Service\Food\FoodCutLexicon;
use App\Service\Food\FoodTermMatcher;
use App\Service\Inventory\InventoryLine;
use App\Service\TextNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * "Forme supposée : X" under a recipe asks the user to check a guess. A real
 * generation showed guesses on foods whose cut was already known — minced
 * steak "assumed" minced, sliced onions "assumed" sliced — and the user was
 * asked to confirm what the app already knew. Only real guesses remain.
 */
class AssumedCutFilterTest extends TestCase
{
    public function testAGuessOnAFoodWhoseCutIsKnownIsDropped(): void
    {
        $recipes = $this->filter('steak-hache', 'ground', [$this->line('steak-hache', 'Steaks hachés', FoodCut::Ground)]);

        self::assertSame('none', $recipes[0]['ingredientsFromInventory'][0]['assumedCut']);
    }

    public function testAGuessTheFoodCannotTakeIsDropped(): void
    {
        // Bread comes whole or sliced: "ground" bread is not a form it is bought in.
        $recipes = $this->filter('pain', 'ground', [$this->line('pain', 'Pain de campagne')]);

        self::assertSame('none', $recipes[0]['ingredientsFromInventory'][0]['assumedCut']);
    }

    public function testAGuessOnADishIsDropped(): void
    {
        $recipes = $this->filter('soupe-potiron', 'cubes', [$this->line('soupe-potiron', 'Soupe de potiron')]);

        self::assertSame('none', $recipes[0]['ingredientsFromInventory'][0]['assumedCut']);
    }

    /** The case the field exists for: an unknown cut, a plausible guess, shown to be corrected. */
    public function testAPlausibleGuessOnAnUnknownCutIsKept(): void
    {
        $recipes = $this->filter('blanc-poulet', 'escalope', [$this->line('blanc-poulet', 'Blanc de poulet')]);

        self::assertSame('escalope', $recipes[0]['ingredientsFromInventory'][0]['assumedCut']);
    }

    /** A food the lexicon does not know may take any cut: nothing to judge the guess against. */
    public function testAGuessOnAnUnknownFoodIsKept(): void
    {
        $recipes = $this->filter('andouillette', 'whole', [$this->line('andouillette', 'Andouillettes')]);

        self::assertSame('whole', $recipes[0]['ingredientsFromInventory'][0]['assumedCut']);
    }

    /** A key the inventory does not have is someone else's problem (the safety filter's). */
    public function testAnUnknownKeyIsLeftAlone(): void
    {
        $recipes = $this->filter('mystere', 'cubes', []);

        self::assertSame('cubes', $recipes[0]['ingredientsFromInventory'][0]['assumedCut']);
    }

    /**
     * @param list<InventoryLine> $inventory
     *
     * @return list<array<string, mixed>>
     */
    private function filter(string $key, string $assumedCut, array $inventory): array
    {
        $normalizer = new TextNormalizer();
        $filter = new AssumedCutFilter(new FoodCutLexicon(new FoodTermMatcher($normalizer)));

        return $filter->apply([[
            'recipeName' => 'Test',
            'ingredientsFromInventory' => [[
                'key' => $key,
                'label' => $key,
                'quantity' => 1,
                'unit' => 'piece',
                'essential' => true,
                'assumedCut' => $assumedCut,
            ]],
        ]], $inventory);
    }

    private function line(string $key, string $name, ?FoodCut $cut = null): InventoryLine
    {
        return new InventoryLine(
            key: $key,
            variantId: 'v',
            label: $name,
            name: $name,
            quantity: 1,
            unit: Unit::Piece,
            expiresSoon: false,
            alreadyOpened: false,
            earliestExpiration: null,
            expirationIsEstimated: false,
            quantityIsEstimated: false,
            category: null,
            locations: [],
            lotCount: 1,
            cut: $cut,
        );
    }
}
