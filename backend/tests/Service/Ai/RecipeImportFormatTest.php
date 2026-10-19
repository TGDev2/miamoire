<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Enum\Unit;
use App\Service\Ai\Import\RecipeImportFormat;
use App\Service\Ai\RecipeNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * The block the other assistant is asked to write is the block the app keeps.
 *
 * If the example asked for a field the normalizer drops, the assistant would
 * spend effort on something lost; if it missed one the normalizer keeps, every
 * imported recipe would come back without it.
 */
class RecipeImportFormatTest extends TestCase
{
    public function testTheExampleSurvivesNormalizationUnchanged(): void
    {
        $normalized = (new RecipeNormalizer())->normalize(RecipeImportFormat::EXAMPLE);

        self::assertEquals(self::sortDeep(RecipeImportFormat::EXAMPLE), self::sortDeep($normalized));
    }

    public function testTheInstructionsCarryTheExampleAndEveryUnit(): void
    {
        $instructions = RecipeImportFormat::instructions();

        self::assertStringContainsString(RecipeImportFormat::exampleJson(), $instructions);
        self::assertStringContainsString('```json', $instructions);

        foreach (Unit::cases() as $unit) {
            self::assertStringContainsString($unit->value, $instructions);
        }
    }

    public function testTheExampleJsonDecodesBackToTheExample(): void
    {
        self::assertEquals(
            RecipeImportFormat::EXAMPLE,
            json_decode(RecipeImportFormat::exampleJson(), true, 512, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private static function sortDeep(array $value): array
    {
        ksort($value);

        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = self::sortDeep($entry);
            }
        }

        return $value;
    }
}
