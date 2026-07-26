<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\Unit;
use App\Service\Ai\RequiredIngredientFilter;
use App\Service\Inventory\InventoryLine;
use PHPUnit\Framework\TestCase;

/**
 * Le contrôle de l'aliment imposé.
 *
 * Il est écrit comme les exclusions alimentaires, et pour la même raison : la
 * consigne au modèle est respectée la plupart du temps, et « la plupart du
 * temps » ne fait pas une contrainte. Les contre-exemples comptent autant que
 * les exemples (leçon n° 14) — écarter une recette conforme serait aussi grave
 * que d'en laisser passer une qui ne l'est pas.
 */
class RequiredIngredientFilterTest extends TestCase
{
    private RequiredIngredientFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new RequiredIngredientFilter();
    }

    /** Sans aliment imposé, le filtre ne doit rien faire du tout. */
    public function testWithoutAnImposedIngredientEverythingPassesThrough(): void
    {
        $recipes = [$this->recipe('Omelette', []), $this->recipe('Soupe', [])];

        $result = $this->filter->apply($recipes, null);

        self::assertCount(2, $result['recipes']);
        self::assertSame([], $result['rejected']);
    }

    public function testARecipeThatDeductsTheImposedIngredientIsKept(): void
    {
        $recipes = [$this->recipe('Gratin', ['courgette'])];

        $result = $this->filter->apply($recipes, $this->line('courgette', 'Courgettes'));

        self::assertCount(1, $result['recipes']);
        self::assertSame([], $result['rejected']);
    }

    public function testARecipeThatIgnoresTheImposedIngredientIsRejectedWithItsReason(): void
    {
        $recipes = [$this->recipe('Pâtes au beurre', ['pate'])];

        $result = $this->filter->apply($recipes, $this->line('courgette', 'Courgettes'));

        self::assertSame([], $result['recipes']);
        self::assertSame('Pâtes au beurre', $result['rejected'][0]['recipeName']);
        self::assertStringContainsString('Courgettes', $result['rejected'][0]['reason']);
    }

    /**
     * La déduction de stock n'est pas le seul témoin de l'usage : un aliment
     * peut figurer dans la recette sans que le modèle ait pensé à l'y déduire.
     * L'écarter alors serait un faux positif — et un contrôle qui écarte les
     * recettes conformes finit par être désactivé.
     */
    public function testAnIngredientListedWithoutBeingDeductedCounts(): void
    {
        $recipe = $this->recipe('Salade', []);
        $recipe['ingredientsFromInventory'] = [['key' => 'courgette', 'label' => 'Courgettes']];

        $result = $this->filter->apply([$recipe], $this->line('courgette', 'Courgettes'));

        self::assertCount(1, $result['recipes']);
    }

    /**
     * Le critère est la clé, jamais le libellé : « Tomates » et « Tomates (en
     * conserve) » sont deux stocks distincts, et une recette qui ouvre une boîte
     * ne vide pas la barquette de tomates fraîches qu'on voulait écouler.
     */
    public function testANeighbouringVariantDoesNotSatisfyTheConstraint(): void
    {
        $recipes = [$this->recipe('Sauce tomate', ['tomate-conserve'])];

        $result = $this->filter->apply($recipes, $this->line('tomate', 'Tomates'));

        self::assertSame([], $result['recipes']);
        self::assertCount(1, $result['rejected']);
    }

    /**
     * @param list<string> $consumedKeys
     *
     * @return array<string, mixed>
     */
    private function recipe(string $name, array $consumedKeys): array
    {
        return [
            'recipeName' => $name,
            'ingredientsFromInventory' => [],
            'stockConsumptionProposal' => array_map(
                static fn (string $key): array => ['key' => $key, 'label' => $key, 'quantity' => 100, 'unit' => 'g'],
                $consumedKeys,
            ),
        ];
    }

    private function line(string $key, string $label): InventoryLine
    {
        return new InventoryLine(
            key: $key,
            variantId: '00000000-0000-0000-0000-000000000000',
            label: $label,
            name: $label,
            quantity: 500,
            unit: Unit::Gram,
            expiresSoon: false,
            alreadyOpened: false,
            earliestExpiration: null,
            expirationIsEstimated: false,
            quantityIsEstimated: false,
            category: null,
            locations: [],
            lotCount: 1,
        );
    }
}
