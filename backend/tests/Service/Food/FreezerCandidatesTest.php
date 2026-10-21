<?php

declare(strict_types=1);

namespace App\Tests\Service\Food;

use App\Service\Food\FoodTermMatcher;
use App\Service\Food\FreezerCandidates;
use App\Service\TextNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Labels as a receipt or a person writes them (lesson nº 2: term by term). */
class FreezerCandidatesTest extends TestCase
{
    private FreezerCandidates $candidates;

    protected function setUp(): void
    {
        $this->candidates = new FreezerCandidates(new FoodTermMatcher(new TextNormalizer()));
    }

    /** @return iterable<string, array{string}> */
    public static function freezable(): iterable
    {
        yield 'poulet fermier' => ['Poulet fermier'];
        yield 'steak haché, receipt label' => ['STEAK HACHE 15% 2X125G'];
        yield 'escalopes de dinde' => ['Escalopes de dinde'];
        yield 'côte de porc' => ['Côte de porc'];
        yield 'filet de poulet, abbreviated' => ['FIL.POULET 400G'];
        yield 'saumon fumé' => ['Saumon fumé'];
        yield 'crevettes' => ['Crevettes roses'];
    }

    /** @return iterable<string, array{string}> */
    public static function notFreezable(): iterable
    {
        yield 'eggs' => ['Œufs plein air'];
        yield 'cheese' => ['Fromage râpé'];
        yield 'fromage blanc' => ['Fromage blanc'];
        yield 'ham' => ['Jambon blanc'];
        yield 'tofu' => ['Tofu fumé'];
        yield 'milk' => ['Lait demi-écrémé'];
        yield 'courgettes' => ['Courgettes'];
    }

    #[DataProvider('freezable')]
    public function testMeatAndFishAreAskedAboutTheFreezer(string $label): void
    {
        self::assertTrue($this->candidates->isCandidate($label), $label);
    }

    #[DataProvider('notFreezable')]
    public function testOtherProteinsAreNot(string $label): void
    {
        self::assertFalse($this->candidates->isCandidate($label), $label);
    }
}
