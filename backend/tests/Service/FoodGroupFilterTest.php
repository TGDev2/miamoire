<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\FoodGroup;
use App\Service\Ai\FoodGroupFilter;
use App\Service\Food\FoodGroupLexicon;
use App\Service\Food\FoodTermMatcher;
use App\Service\TextNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Le contrôle de la composition demandée — féculent, légume, protéine.
 *
 * Écarter une recette conforme serait aussi grave que d'en laisser passer une
 * qui ne l'est pas (leçon n° 14) : les deux sens sont testés.
 */
class FoodGroupFilterTest extends TestCase
{
    private FoodGroupFilter $filter;

    protected function setUp(): void
    {
        $normalizer = new TextNormalizer();
        $this->filter = new FoodGroupFilter(new FoodGroupLexicon(new FoodTermMatcher($normalizer)), $normalizer);
    }

    /** Le cas observé dans l'application : le seul légume était facultatif. */
    public function testAGroupProvidedOnlyByAnOptionalIngredientDoesNotCount(): void
    {
        $recipe = $this->recipe('Purée et émincé de poulet', [
            'starch' => 'Pommes de terre',
            'protein' => 'Blancs de poulet',
            'vegetable' => 'Carottes',
        ]);
        $recipe['ingredientsFromInventory'] = [
            ['label' => 'Pommes de terre', 'essential' => true],
            ['label' => 'Blancs de poulet', 'essential' => true],
            ['label' => 'Carottes', 'essential' => false],
        ];

        $result = $this->filter->apply([$recipe], [FoodGroup::Vegetable, FoodGroup::Protein]);

        self::assertSame([], $result['recipes']);
        self::assertSame('sans légume', $result['rejected'][0]['reason']);
    }

    /**
     * Le même plat dont la carotte est essentielle passe, et un libellé que
     * le filtre ne retrouve pas dans les ingrédients n'est pas présumé
     * facultatif.
     */
    public function testAnEssentialOrUnmatchedIngredientStillProvidesItsGroup(): void
    {
        $recipe = $this->recipe('Purée et émincé de poulet', ['vegetable' => 'Carottes', 'protein' => 'Poulet']);
        $recipe['ingredientsFromInventory'] = [['label' => 'carottes', 'essential' => true]];
        $recipe['missingIngredients'] = [['label' => 'Blancs de poulet', 'essential' => true]];

        $result = $this->filter->apply([$recipe], [FoodGroup::Vegetable, FoodGroup::Protein]);

        self::assertCount(1, $result['recipes']);
    }

    /**
     * Le cas observé en génération réelle : sans protéine en stock, le modèle
     * a rangé le riz parmi les protéines plutôt que d'avouer le manque.
     */
    public function testADeclarationContradictedByTheLexiconDoesNotCount(): void
    {
        $recipes = [$this->recipe('Tian de courgettes au riz', [
            'vegetable' => 'Courgettes',
            'starch' => 'Riz basmati',
            'protein' => 'Riz basmati',
        ])];

        $result = $this->filter->apply($recipes, [FoodGroup::Protein]);

        self::assertSame([], $result['recipes']);
        self::assertSame('sans protéine', $result['rejected'][0]['reason']);
    }

    /** Un aliment que le lexique ne connaît pas n'est pas suspect. */
    public function testAnIngredientUnknownToTheLexiconIsTakenAtItsWord(): void
    {
        $recipes = [$this->recipe('Bowl de quinoa', ['protein' => 'Quinoa'])];

        $result = $this->filter->apply($recipes, [FoodGroup::Protein]);

        self::assertCount(1, $result['recipes']);
    }

    /** Une seule déclaration fausse ne gâche pas une vraie, plus loin dans la liste. */
    public function testAContradictedDeclarationDoesNotHideAValidOne(): void
    {
        $recipes = [['recipeName' => 'Riz cantonais', 'foodGroups' => [
            ['group' => 'protein', 'ingredient' => 'Riz'],
            ['group' => 'protein', 'ingredient' => 'Œufs'],
        ]]];

        $result = $this->filter->apply($recipes, [FoodGroup::Protein]);

        self::assertCount(1, $result['recipes']);
    }

    /** Sans groupe demandé, même une recette sans déclaration passe. */
    public function testWithoutRequestedGroupsEverythingPassesThrough(): void
    {
        $recipes = [['recipeName' => 'Omelette'], $this->recipe('Soupe', [])];

        $result = $this->filter->apply($recipes, []);

        self::assertCount(2, $result['recipes']);
        self::assertSame([], $result['rejected']);
    }

    public function testARecipeDeclaringEveryRequestedGroupIsKept(): void
    {
        $recipes = [$this->recipe('Poulet basquaise et riz', [
            'protein' => 'Blanc de poulet',
            'vegetable' => 'Poivrons',
            'starch' => 'Riz',
        ])];

        $result = $this->filter->apply($recipes, [FoodGroup::Starch, FoodGroup::Protein]);

        self::assertCount(1, $result['recipes']);
        self::assertSame([], $result['rejected']);
    }

    public function testARecipeMissingARequestedGroupIsRejectedWithItsReason(): void
    {
        $recipes = [$this->recipe('Ratatouille', ['vegetable' => 'Courgettes'])];

        $result = $this->filter->apply($recipes, [FoodGroup::Vegetable, FoodGroup::Protein]);

        self::assertSame([], $result['recipes']);
        self::assertSame(
            [['recipeName' => 'Ratatouille', 'reason' => 'sans protéine']],
            $result['rejected'],
        );
    }

    /** Le motif nomme tous les groupes manquants, dans l'ordre demandé. */
    public function testTheReasonListsEveryMissingGroup(): void
    {
        $recipes = [$this->recipe('Salade verte', ['vegetable' => 'Laitue'])];

        $result = $this->filter->apply($recipes, [FoodGroup::Starch, FoodGroup::Vegetable, FoodGroup::Protein]);

        self::assertSame('sans féculent ni protéine', $result['rejected'][0]['reason']);
    }

    /** Une case cochée sans ingrédient n'est pas un engagement. */
    public function testAGroupDeclaredWithoutAnIngredientDoesNotCount(): void
    {
        $recipes = [$this->recipe('Gratin', ['starch' => '   '])];

        $result = $this->filter->apply($recipes, [FoodGroup::Starch]);

        self::assertSame([], $result['recipes']);
        self::assertSame('sans féculent', $result['rejected'][0]['reason']);
    }

    /** Une réponse qui omet le champ ou le corrompt est écartée, pas fatale. */
    public function testAMissingOrMalformedDeclarationIsRejectedWithoutError(): void
    {
        $recipes = [
            ['recipeName' => 'Sans champ'],
            ['recipeName' => 'Champ texte', 'foodGroups' => 'starch'],
            ['recipeName' => 'Entrées bancales', 'foodGroups' => ['starch', ['group' => 'starch']]],
            ['foodGroups' => []],
        ];

        $result = $this->filter->apply($recipes, [FoodGroup::Starch]);

        self::assertSame([], $result['recipes']);
        self::assertSame(
            ['Sans champ', 'Champ texte', 'Entrées bancales', 'Proposition sans nom'],
            array_column($result['rejected'], 'recipeName'),
        );
    }

    /** Les recettes conformes gardent leur ordre, les autres sortent sans le troubler. */
    public function testOnlyNonCompliantRecipesAreRemovedFromAMixedBatch(): void
    {
        $recipes = [
            $this->recipe('Dahl de lentilles', ['starch' => 'Lentilles corail', 'protein' => 'Lentilles corail']),
            $this->recipe('Velouté de potiron', ['vegetable' => 'Potiron']),
            $this->recipe('Omelette aux pommes de terre', ['protein' => 'Œufs', 'starch' => 'Pommes de terre']),
        ];

        $result = $this->filter->apply($recipes, [FoodGroup::Protein]);

        self::assertSame(
            ['Dahl de lentilles', 'Omelette aux pommes de terre'],
            array_column($result['recipes'], 'recipeName'),
        );
        self::assertSame(['Velouté de potiron'], array_column($result['rejected'], 'recipeName'));
    }

    /**
     * @param array<string, string> $groups groupe => ingrédient qui le fournit
     *
     * @return array<string, mixed>
     */
    private function recipe(string $name, array $groups): array
    {
        $declared = [];

        foreach ($groups as $group => $ingredient) {
            $declared[] = ['group' => $group, 'ingredient' => $ingredient];
        }

        return ['recipeName' => $name, 'foodGroups' => $declared];
    }
}
