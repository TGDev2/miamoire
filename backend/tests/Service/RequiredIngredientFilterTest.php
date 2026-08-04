<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\Unit;
use App\Service\Ai\RequiredIngredientFilter;
use App\Service\Ai\ResolvedPriority;
use App\Service\Inventory\InventoryLine;
use App\Service\UnitConverter;
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
        $this->filter = new RequiredIngredientFilter(new UnitConverter());
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

        $result = $this->filter->apply($recipes, $this->priority('courgette', 'Courgettes'));

        self::assertCount(1, $result['recipes']);
        self::assertSame([], $result['rejected']);
    }

    public function testARecipeThatIgnoresTheImposedIngredientIsRejectedWithItsReason(): void
    {
        $recipes = [$this->recipe('Pâtes au beurre', ['pate'])];

        $result = $this->filter->apply($recipes, $this->priority('courgette', 'Courgettes'));

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

        $result = $this->filter->apply([$recipe], $this->priority('courgette', 'Courgettes'));

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

        $result = $this->filter->apply($recipes, $this->priority('tomate', 'Tomates'));

        self::assertSame([], $result['recipes']);
        self::assertCount(1, $result['rejected']);
    }

    /**
     * La quantité imposée : une recette qui utilise bien l'aliment mais en
     * écoule le quart ne répond pas à la demande — le lot périmera quand même,
     * ce qui est précisément ce qu'on cherchait à éviter.
     */
    public function testARecipeFallingWellShortOfTheTargetIsRejected(): void
    {
        $recipes = [$this->consuming('Salade', 'courgette', 100, 'g')];

        $result = $this->filter->apply($recipes, $this->priority('courgette', 'Courgettes', 400));

        self::assertSame([], $result['recipes']);
        self::assertStringContainsString('100 g', $result['rejected'][0]['reason']);
        self::assertStringContainsString('400 g', $result['rejected'][0]['reason']);
    }

    /**
     * Une recette pour deux ne tombe jamais au gramme près. Exiger 100 % de la
     * cible écarterait des propositions parfaitement conformes à l'intention —
     * d'où la tolérance, et d'où ce test qui la fige.
     */
    public function testARecipeWithinToleranceOfTheTargetIsKept(): void
    {
        $recipes = [$this->consuming('Poêlée', 'courgette', 340, 'g')];

        $result = $this->filter->apply($recipes, $this->priority('courgette', 'Courgettes', 400));

        self::assertCount(1, $result['recipes']);
        self::assertSame([], $result['rejected']);
    }

    /** Écouler plus que demandé n'est pas une faute. */
    public function testARecipeExceedingTheTargetIsKept(): void
    {
        $recipes = [$this->consuming('Gratin', 'courgette', 500, 'g')];

        $result = $this->filter->apply($recipes, $this->priority('courgette', 'Courgettes', 400));

        self::assertCount(1, $result['recipes']);
    }

    /** Plusieurs lignes pour la même clé s'additionnent avant d'être jugées. */
    public function testQuantitiesForTheSameKeyAreAddedUp(): void
    {
        $recipe = $this->consuming('Tian', 'courgette', 250, 'g');
        $recipe['stockConsumptionProposal'][] = ['key' => 'courgette', 'label' => 'Courgettes', 'quantity' => 200, 'unit' => 'g'];

        $result = $this->filter->apply([$recipe], $this->priority('courgette', 'Courgettes', 400));

        self::assertCount(1, $result['recipes']);
    }

    /** Une unité d'une autre famille se convertit sans rien perdre. */
    public function testAQuantityWrittenInAnotherUnitOfTheSameFamilyIsConverted(): void
    {
        $recipes = [$this->consuming('Cake', 'courgette', 0.5, 'kg')];

        $result = $this->filter->apply($recipes, $this->priority('courgette', 'Courgettes', 400));

        self::assertCount(1, $result['recipes'], '0,5 kg valent bien 500 g.');
    }

    /**
     * Le cas où l'on ne sait pas mesurer : le modèle a écrit « 2 portions » là
     * où la cible est en grammes. Ne pas pouvoir mesurer n'est pas une preuve
     * d'échec — le contrôle de la clé a déjà eu lieu, et écarter ici serait
     * punir une recette pour une incertitude qui nous appartient.
     */
    public function testAnUnmeasurableQuantityDoesNotCauseARejection(): void
    {
        $recipes = [$this->consuming('Velouté', 'courgette', 2, 'portion')];

        $result = $this->filter->apply($recipes, $this->priority('courgette', 'Courgettes', 400));

        self::assertCount(1, $result['recipes']);
        self::assertSame([], $result['rejected']);
    }

    /**
     * L'aliment nommé sans être déduit satisfait le contrôle de clé, mais ne
     * prouve aucune quantité : « écouler » parle de ce qui sort du stock.
     */
    public function testAnIngredientNeverDeductedCannotSatisfyATarget(): void
    {
        $recipe = $this->recipe('Salade', []);
        $recipe['ingredientsFromInventory'] = [['key' => 'courgette', 'label' => 'Courgettes']];

        $result = $this->filter->apply([$recipe], $this->priority('courgette', 'Courgettes', 400));

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

    /** @return array<string, mixed> */
    private function consuming(string $name, string $key, float $quantity, string $unit): array
    {
        return [
            'recipeName' => $name,
            'ingredientsFromInventory' => [],
            'stockConsumptionProposal' => [
                ['key' => $key, 'label' => $key, 'quantity' => $quantity, 'unit' => $unit],
            ],
        ];
    }

    private function priority(string $key, string $label, ?float $target = null): ResolvedPriority
    {
        return new ResolvedPriority(
            $this->line($key, $label),
            $target,
            null !== $target ? Unit::Gram : null,
        );
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
