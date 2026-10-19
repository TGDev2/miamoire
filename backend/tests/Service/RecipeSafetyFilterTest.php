<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Ai\Safety\DietaryGuard;
use App\Service\Ai\Safety\RecipeSafetyFilter;
use App\Service\TextNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Une recette peut être conforme dans sa liste d'ingrédients et introduire
 * l'allergène ailleurs — à l'étape 4, dans une substitution proposée en passant,
 * ou dans la ligne d'un ingrédient à acheter.
 *
 * Le filtre inspecte donc tout ce qui nomme un aliment, et **retire** la recette
 * plutôt que de l'annoter : afficher « attention, contient des arachides »
 * reviendrait à déléguer la vérification à celui qui lit.
 */
class RecipeSafetyFilterTest extends TestCase
{
    private RecipeSafetyFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new RecipeSafetyFilter();
    }

    private function guard(string ...$exclusions): DietaryGuard
    {
        return new DietaryGuard(new TextNormalizer(), array_values($exclusions));
    }

    /** @param array<string, mixed> $overrides */
    private function recipe(array $overrides = []): array
    {
        return $overrides + [
            'recipeName' => 'Poêlée de légumes',
            'summary' => 'Un plat simple et rapide.',
            'ingredientsFromInventory' => [['key' => 'courgette', 'label' => 'Courgettes']],
            'missingIngredients' => [],
            'substitutions' => [],
            'steps' => ['Couper les courgettes', 'Faire revenir 10 minutes'],
            'stockConsumptionProposal' => [['key' => 'courgette', 'label' => 'Courgettes']],
        ];
    }

    public function testACompliantRecipeIsKept(): void
    {
        $result = $this->filter->apply([$this->recipe()], $this->guard('arachide'));

        self::assertCount(1, $result['recipes']);
        self::assertSame([], $result['rejected']);
    }

    /** Le cas le plus insidieux : tout est propre, sauf la dernière étape. */
    public function testARecipeIsRejectedOnASingleStep(): void
    {
        $recipe = $this->recipe([
            'steps' => ['Couper les courgettes', 'Parsemer de cacahuètes concassées'],
        ]);

        $result = $this->filter->apply([$recipe], $this->guard('arachide'));

        self::assertSame([], $result['recipes']);
        self::assertCount(1, $result['rejected']);
        self::assertStringContainsString('Arachides', $result['rejected'][0]['reason']);
        self::assertSame('Poêlée de légumes', $result['rejected'][0]['recipeName']);
    }

    public function testARecipeIsRejectedOnASubstitution(): void
    {
        $recipe = $this->recipe([
            'substitutions' => [[
                'original' => 'Huile d\'olive',
                'replacedBy' => 'Huile d\'arachide',
                'reason' => 'Plus adaptée à la friture',
            ]],
        ]);

        $result = $this->filter->apply([$recipe], $this->guard('arachide'));

        self::assertSame([], $result['recipes']);
    }

    public function testARecipeIsRejectedOnAMissingIngredient(): void
    {
        $recipe = $this->recipe([
            'missingIngredients' => [['label' => 'Sauce satay', 'quantity' => 2, 'unit' => 'piece']],
        ]);

        self::assertSame([], $this->filter->apply([$recipe], $this->guard('arachide'))['recipes']);
    }

    /**
     * La proposition de consommation se juge sur le libellé RÉEL de l'inventaire :
     * c'est lui qui décrit l'aliment qui serait retiré du stock, quel que soit le
     * nom que le modèle a recopié.
     */
    public function testTheProposalIsJudgedOnTheRealInventoryLabel(): void
    {
        $recipe = $this->recipe([
            'stockConsumptionProposal' => [['key' => 'courgette', 'label' => 'Courgettes'], ['key' => 'sauce', 'label' => 'Sauce maison']],
        ]);

        $result = $this->filter->apply(
            [$recipe],
            $this->guard('arachide'),
            ['courgette' => 'Courgettes', 'sauce' => 'Sauce aux cacahuètes'],
        );

        self::assertSame([], $result['recipes']);
        self::assertStringEndsWith('dans la déduction de stock 2)', $result['rejected'][0]['reason']);
    }

    /**
     * An ingredient named by its key is the household's food, whatever the
     * recipe calls it. A hand-edited or imported recipe can say "Pâte à
     * tartiner" for the peanut butter of the stock.
     */
    public function testAnIngredientIsJudgedOnTheRealInventoryLabelOfItsKey(): void
    {
        $recipe = $this->recipe([
            'ingredientsFromInventory' => [
                ['key' => 'courgette', 'label' => 'Courgettes'],
                ['key' => 'beurre-de-cacahuete', 'label' => 'Pâte à tartiner'],
            ],
        ]);

        $result = $this->filter->apply(
            [$recipe],
            $this->guard('arachide'),
            ['courgette' => 'Courgettes', 'beurre-de-cacahuete' => 'Beurre de cacahuète'],
        );

        self::assertSame([], $result['recipes']);
        // Numbered by line, as the user reads them — not by the texts read per line.
        self::assertStringEndsWith('dans les ingrédients 2)', $result['rejected'][0]['reason']);
    }

    public function testAnIngredientsRealLabelStillHonoursTheCounterExamples(): void
    {
        $recipe = $this->recipe([
            'ingredientsFromInventory' => [['key' => 'lait-de-coco', 'label' => 'Coco']],
        ]);

        $result = $this->filter->apply([$recipe], $this->guard('lait'), ['lait-de-coco' => 'Lait de coco']);

        self::assertCount(1, $result['recipes']);
    }

    public function testOnlyTheOffendingRecipeIsRemoved(): void
    {
        $result = $this->filter->apply(
            [
                $this->recipe(),
                $this->recipe(['recipeName' => 'Nouilles satay']),
                $this->recipe(['recipeName' => 'Soupe de courgettes']),
            ],
            $this->guard('arachide'),
        );

        self::assertCount(2, $result['recipes']);
        self::assertCount(1, $result['rejected']);
        self::assertSame('Nouilles satay', $result['rejected'][0]['recipeName']);
    }

    public function testWithoutExclusionsNothingIsFiltered(): void
    {
        $recipe = $this->recipe(['steps' => ['Napper de beurre de cacahuète']]);

        $result = $this->filter->apply([$recipe], $this->guard());

        self::assertCount(1, $result['recipes']);
    }

    /** Un contre-exemple ne doit pas faire disparaître une recette parfaitement valable. */
    public function testACounterExampleDoesNotRejectAValidRecipe(): void
    {
        $recipe = $this->recipe([
            'recipeName' => 'Curry de légumes au lait de coco',
            'steps' => ['Verser le lait de coco', 'Laisser mijoter'],
        ]);

        self::assertCount(1, $this->filter->apply([$recipe], $this->guard('lait'))['recipes']);
    }
}
