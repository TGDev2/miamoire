<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\Unit;
use App\Exception\StockException;
use App\Service\Stock\ProposalConsolidator;
use App\Service\UnitConverter;
use PHPUnit\Framework\TestCase;

/**
 * Deux lignes du même ingrédient réservaient chacune le même lot.
 *
 * Le plan annonçait alors plus que le stock réel, l'écriture plafonnait en
 * silence, et la réponse affirmait avoir retiré une quantité qui ne l'avait pas
 * été. Consolider avant de planifier est ce qui rend le plan honnête.
 */
class ProposalConsolidatorTest extends TestCase
{
    private ProposalConsolidator $consolidator;

    protected function setUp(): void
    {
        $this->consolidator = new ProposalConsolidator(new UnitConverter());
    }

    public function testDuplicateLinesAreMerged(): void
    {
        $lines = $this->consolidator->consolidate([
            ['key' => 'tomate', 'label' => 'Tomates', 'quantity' => 200, 'unit' => 'g'],
            ['key' => 'tomate', 'label' => 'Tomates', 'quantity' => 100, 'unit' => 'g'],
        ]);

        self::assertCount(1, $lines);
        self::assertSame(300.0, $lines[0]->quantity);
    }

    public function testUnitsAreConvertedBeforeBeingSummed(): void
    {
        $lines = $this->consolidator->consolidate([
            ['key' => 'farine', 'label' => 'Farine', 'quantity' => 500, 'unit' => 'g'],
            ['key' => 'farine', 'label' => 'Farine', 'quantity' => 1, 'unit' => 'kg'],
        ]);

        self::assertCount(1, $lines);
        self::assertSame(1500.0, $lines[0]->quantity);
        self::assertSame(Unit::Gram, $lines[0]->unit, 'La première unité rencontrée fait référence.');
    }

    /** Additionner 2 pièces et 300 g demanderait un poids unitaire qu'on n'a pas. */
    public function testIncompatibleUnitsForTheSameKeyAreRejected(): void
    {
        $this->expectException(StockException::class);
        $this->expectExceptionMessage('mélange');

        $this->consolidator->consolidate([
            ['key' => 'oeuf', 'label' => 'Œufs', 'quantity' => 2, 'unit' => 'piece'],
            ['key' => 'oeuf', 'label' => 'Œufs', 'quantity' => 300, 'unit' => 'g'],
        ]);
    }

    public function testDistinctKeysStayDistinct(): void
    {
        $lines = $this->consolidator->consolidate([
            ['key' => 'tomate', 'label' => 'Tomates', 'quantity' => 200, 'unit' => 'g'],
            ['key' => 'tomate-conserve', 'label' => 'Tomates (en conserve)', 'quantity' => 400, 'unit' => 'g'],
        ]);

        self::assertCount(2, $lines, 'Deux états d\'un aliment sont deux stocks.');
    }

    public function testInvalidLinesAreReportedAllAtOnce(): void
    {
        $this->expectException(StockException::class);

        try {
            $this->consolidator->consolidate([
                ['key' => '', 'quantity' => 200, 'unit' => 'g'],
                ['key' => 'riz', 'quantity' => 100, 'unit' => 'parsecs'],
                ['key' => 'lait', 'quantity' => -3, 'unit' => 'l'],
            ]);
        } catch (StockException $exception) {
            self::assertStringContainsString('Ligne 1', $exception->getMessage());
            self::assertStringContainsString('Ligne 2', $exception->getMessage());
            self::assertStringContainsString('Ligne 3', $exception->getMessage());

            throw $exception;
        }
    }

    public function testAnEmptyProposalIsRejected(): void
    {
        $this->expectException(StockException::class);
        $this->consolidator->consolidate([]);
    }

    /** Le rejeu d'une même clé d'opération doit reconnaître une requête identique… */
    public function testTheHashIgnoresLineOrder(): void
    {
        $first = $this->consolidator->consolidate([
            ['key' => 'tomate', 'quantity' => 200, 'unit' => 'g'],
            ['key' => 'riz', 'quantity' => 100, 'unit' => 'g'],
        ]);
        $second = $this->consolidator->consolidate([
            ['key' => 'riz', 'quantity' => 100, 'unit' => 'g'],
            ['key' => 'tomate', 'quantity' => 200, 'unit' => 'g'],
        ]);

        self::assertSame(
            $this->consolidator->hash($first, 'Ratatouille'),
            $this->consolidator->hash($second, 'Ratatouille'),
        );
    }

    /** …et refuser une requête différente sous la même clé. */
    public function testTheHashChangesWithTheContent(): void
    {
        $lines = $this->consolidator->consolidate([['key' => 'riz', 'quantity' => 100, 'unit' => 'g']]);
        $other = $this->consolidator->consolidate([['key' => 'riz', 'quantity' => 200, 'unit' => 'g']]);

        self::assertNotSame(
            $this->consolidator->hash($lines, 'Risotto'),
            $this->consolidator->hash($other, 'Risotto'),
        );
        self::assertNotSame(
            $this->consolidator->hash($lines, 'Risotto'),
            $this->consolidator->hash($lines, 'Riz cantonais'),
        );
    }
}
