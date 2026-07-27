<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\StockException;
use App\Service\Ai\RecipeNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Ce qu'on accepte de conserver sous le nom de « recette », et ce qui la rend
 * unique.
 *
 * Une recette enregistrée est du JSON venu du téléphone : sans liste blanche
 * n'importe quel document finirait en base, et serait renvoyé tel quel à tous
 * les membres du foyer. Et sans empreinte stable, un double appui créerait deux
 * fois la même carte dans la liste.
 */
class RecipeNormalizerTest extends TestCase
{
    private RecipeNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new RecipeNormalizer();
    }

    public function testUnknownFieldsAreDropped(): void
    {
        $recipe = $this->normalizer->normalize([
            'recipeName' => 'Gratin de courgettes',
            'summary' => 'Simple et rapide',
            'malveillant' => ['<script>', str_repeat('x', 100000)],
        ]);

        self::assertArrayNotHasKey('malveillant', $recipe);
        self::assertSame('Gratin de courgettes', $recipe['recipeName']);
    }

    public function testARecipeWithoutANameIsRefused(): void
    {
        $this->expectException(StockException::class);

        $this->normalizer->normalize(['summary' => 'Sans titre']);
    }

    /**
     * Une ligne de déduction inexploitable est retirée à l'enregistrement, pas
     * découverte six mois plus tard au moment où l'utilisateur appuie sur
     * « retirer du stock » — il n'aurait alors aucun moyen de la réparer.
     */
    public function testConsumptionLinesThatCouldNeverDeductAreRemoved(): void
    {
        $recipe = $this->normalizer->normalize([
            'recipeName' => 'Gratin',
            'stockConsumptionProposal' => [
                ['key' => 'courgette', 'label' => 'Courgettes', 'quantity' => 300, 'unit' => 'g'],
                ['key' => '', 'label' => 'Sans clé', 'quantity' => 100, 'unit' => 'g'],
                ['key' => 'creme', 'label' => 'Crème', 'quantity' => 100, 'unit' => 'louche'],
                ['key' => 'sel', 'label' => 'Sel', 'quantity' => 0, 'unit' => 'g'],
            ],
        ]);

        self::assertCount(1, $recipe['stockConsumptionProposal']);
        self::assertSame('courgette', $recipe['stockConsumptionProposal'][0]['key']);
    }

    /** Deux sérialisations du même objet doivent donner la même empreinte. */
    public function testFingerprintIgnoresKeyOrder(): void
    {
        $first = $this->normalizer->normalize(['recipeName' => 'Gratin', 'summary' => 'Bon', 'servings' => 4]);
        $second = $this->normalizer->normalize(['servings' => 4, 'summary' => 'Bon', 'recipeName' => 'Gratin']);

        self::assertSame($this->normalizer->fingerprint($first), $this->normalizer->fingerprint($second));
    }

    public function testFingerprintSeparatesTwoDifferentRecipes(): void
    {
        $first = $this->normalizer->normalize(['recipeName' => 'Gratin', 'servings' => 4]);
        $second = $this->normalizer->normalize(['recipeName' => 'Gratin', 'servings' => 2]);

        self::assertNotSame($this->normalizer->fingerprint($first), $this->normalizer->fingerprint($second));
    }

    /** Un ingrédient à acheter n'a pas de clé d'inventaire : lui en inventer une le rendrait déductible. */
    public function testMissingIngredientsNeverCarryAnInventoryKey(): void
    {
        $recipe = $this->normalizer->normalize([
            'recipeName' => 'Gratin',
            'missingIngredients' => [
                ['key' => 'gruyere', 'label' => 'Gruyère', 'quantity' => 100, 'unit' => 'g', 'essential' => true],
            ],
        ]);

        self::assertArrayNotHasKey('key', $recipe['missingIngredients'][0]);
        self::assertSame('Gruyère', $recipe['missingIngredients'][0]['label']);
    }

    /** Sans ce champ, une recette enregistrée perdrait silencieusement l'information. */
    public function testIncludesSideDishIsKept(): void
    {
        $recipe = $this->normalizer->normalize([
            'recipeName' => 'Poulet rôti',
            'includesSideDish' => true,
        ]);

        self::assertTrue($recipe['includesSideDish']);
    }

    public function testIncludesSideDishDefaultsToFalse(): void
    {
        $recipe = $this->normalizer->normalize(['recipeName' => 'Quiche lorraine']);

        self::assertFalse($recipe['includesSideDish']);
    }
}
