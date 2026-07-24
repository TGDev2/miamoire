<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\IngredientKeyGenerator;
use PHPUnit\Framework\TestCase;

class IngredientKeyGeneratorTest extends TestCase
{
    private IngredientKeyGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new IngredientKeyGenerator();
    }

    /**
     * Sans cette normalisation, « Tomates » et « tomate » seraient deux ingrédients
     * distincts et la déduction de stock proposée par l'IA échouerait.
     */
    public function testSingularAndPluralShareTheSameKey(): void
    {
        self::assertSame(
            $this->generator->generate('Tomate'),
            $this->generator->generate('Tomates'),
        );
    }

    public function testStripsAccentsAndCase(): void
    {
        self::assertSame('creme-fraiche', $this->generator->generate('Crème Fraîche'));
    }

    public function testStripsPackagingInformation(): void
    {
        self::assertSame('pate-penne', $this->generator->generate('Pâtes Penne 500g'));
        self::assertSame('lait-demi-ecreme', $this->generator->generate('Lait demi-écrémé 1 L'));
    }

    /** Une singularisation trop agressive casserait ces mots. */
    public function testDoesNotOverSingularize(): void
    {
        self::assertSame('riz', $this->generator->generate('Riz'));
        self::assertSame('ananas', $this->generator->generate('Ananas'));
        self::assertSame('jus', $this->generator->generate('Jus'));
    }

    public function testIgnoresNoiseWords(): void
    {
        self::assertSame('huile-olive', $this->generator->generate("Huile d'olive bio"));
    }

    public function testFallsBackWhenNothingUsableRemains(): void
    {
        self::assertSame('inconnu', $this->generator->generate('500 g'));
    }

    public function testKeepsKeyShortEnoughToMatch(): void
    {
        self::assertSame(
            'pate-penne-rigate',
            $this->generator->generate('Pâtes Penne Rigate Barilla Qualité Supérieure'),
        );
    }
}
