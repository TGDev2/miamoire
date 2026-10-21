<?php

declare(strict_types=1);

namespace App\Tests\Service\Food;

use App\Enum\FoodGroup;
use App\Service\Food\FoodGroupLexicon;
use App\Service\Food\FoodTermMatcher;
use App\Service\TextNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Chaque cas est un libellé tel qu'un inventaire ou un modèle l'écrit, avec ses
 * accents, ses pluriels et ses pièges (leçon n° 2 : une liste de termes se
 * vérifie aliment par aliment).
 */
class FoodGroupLexiconTest extends TestCase
{
    private FoodGroupLexicon $lexicon;

    protected function setUp(): void
    {
        $this->lexicon = new FoodGroupLexicon(new FoodTermMatcher(new TextNormalizer()));
    }

    /** @return iterable<string, array{string, list<FoodGroup>}> */
    public static function knownFoods(): iterable
    {
        // Le cas réel qui a motivé ce lexique.
        yield 'riz basmati' => ['Riz basmati', [FoodGroup::Starch]];
        yield 'pâtes au pluriel' => ['Pâtes penne', [FoodGroup::Starch]];
        yield 'pomme de terre, pas légume' => ['Pommes de terre', [FoodGroup::Starch]];
        yield 'légumineuse, deux groupes' => ['Lentilles corail', [FoodGroup::Starch, FoodGroup::Protein]];
        yield 'pois chiches' => ['Pois chiches', [FoodGroup::Starch, FoodGroup::Protein]];
        // Read off a receipt: no "viande", no "boeuf", still meat.
        yield 'steak haché' => ['Steak haché', [FoodGroup::Protein]];
        // Le terme le plus long gagne : vert, c'est un légume.
        yield 'haricots verts' => ['Haricots verts', [FoodGroup::Vegetable]];
        yield 'haricots rouges' => ['Haricots rouges en conserve', [FoodGroup::Starch, FoodGroup::Protein]];
        yield 'chou-fleur' => ['Chou-fleur', [FoodGroup::Vegetable]];
        yield 'courgettes' => ['Courgettes', [FoodGroup::Vegetable]];
        yield 'blancs de poulet' => ['Blancs de poulet', [FoodGroup::Protein]];
        yield 'œufs, ligature' => ['Œufs frais', [FoodGroup::Protein]];
        yield 'oeufs, sans ligature' => ['Oeufs', [FoodGroup::Protein]];
        yield 'emmental râpé' => ['Emmental râpé', [FoodGroup::Protein]];
    }

    /** @param list<FoodGroup> $groups */
    #[DataProvider('knownFoods')]
    public function testKnownFoodsHaveTheirGroups(string $label, array $groups): void
    {
        self::assertSame($groups, $this->lexicon->groupsOf($label));
    }

    /**
     * Inconnu n'est pas suspect : `null` laisse le modèle juger. Chacun de ces
     * libellés serait mal rangé par un terme trop gourmand.
     *
     * @return iterable<string, array{string}>
     */
    public static function unknownFoods(): iterable
    {
        yield 'pâte feuilletée, pas des pâtes' => ['Pâte feuilletée'];
        yield 'pâté, pas des pâtes' => ['Pâté de campagne'];
        yield 'pomme, pas pomme de terre' => ['Pommes golden'];
        yield 'quinoa, classement discuté' => ['Quinoa'];
        yield 'haricot seul, vert ou sec' => ['Haricots'];
        yield 'crème fraîche' => ['Crème fraîche épaisse'];
    }

    #[DataProvider('unknownFoods')]
    public function testUnknownOrAmbiguousFoodsAreLeftToTheModel(string $label): void
    {
        self::assertNull($this->lexicon->groupsOf($label));
    }
}
