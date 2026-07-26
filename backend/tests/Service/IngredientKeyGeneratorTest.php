<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\IngredientKeyGenerator;
use App\Service\TextNormalizer;
use PHPUnit\Framework\TestCase;

class IngredientKeyGeneratorTest extends TestCase
{
    private IngredientKeyGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new IngredientKeyGenerator(new TextNormalizer());
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

    /**
     * Le cas rencontré en base : « Carottes en rondelles, Surgelé » donnait
     * `carotte-rondelle`, mais « Carottes en Rondelles Surgelés » donnait
     * `carotte-rondelle-surgele`. Le même aliment sous deux clés, donc deux
     * variantes que rien ne distinguait à l'écran.
     */
    public function testNoiseWordsAreIgnoredAtThePluralToo(): void
    {
        self::assertSame(
            $this->generator->generate('Carottes en rondelles, Surgelé'),
            $this->generator->generate('Carottes en Rondelles Surgelés'),
        );
        self::assertSame('carotte-rondelle', $this->generator->generate('Carottes en Rondelles Surgelés'));
        self::assertSame('tomate', $this->generator->generate('Tomates bios'));
    }

    /**
     * Chercher le mot vide au singulier ne doit pas se mettre à effacer des
     * aliments : seuls les mots qui n'identifient pas l'aliment disparaissent.
     */
    public function testAFoodIsNeverMistakenForANoiseWord(): void
    {
        self::assertSame('lentille', $this->generator->generate('Lentilles'));
        self::assertSame('creme-fraiche', $this->generator->generate('Crèmes fraîches'));
        self::assertSame('pomme-terre', $this->generator->generate('Pommes de terre'));
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
