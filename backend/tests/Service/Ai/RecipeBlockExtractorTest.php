<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Service\Ai\Import\RecipeBlockExtractor;
use App\Service\Ai\Import\RecipeImportFormat;
use PHPUnit\Framework\TestCase;

/**
 * What the user pastes is an assistant's answer copied from a chat: prose,
 * Markdown, one or several JSON blocks — sometimes cut short by a selection
 * that stopped too early. The extractor finds the recipes, and says which ones
 * it saw but could not read, rather than guessing.
 */
class RecipeBlockExtractorTest extends TestCase
{
    private RecipeBlockExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new RecipeBlockExtractor();
    }

    public function testAFencedBlockInsideProseIsFound(): void
    {
        $text = "## Poêlée de courgettes\n\nUne poêlée rapide.\n\n```json\n".self::block('Poêlée de courgettes')."\n```\n\nBon appétit !";

        $result = $this->extractor->extract($text);

        self::assertSame(['Poêlée de courgettes'], self::names($result['recipes']));
        self::assertSame([], $result['unreadable']);
    }

    /** A selection copied from a rendered chat loses the fences. */
    public function testABlockWithoutFencesIsFound(): void
    {
        $text = "Poêlée de courgettes\njson\nCopier le code\n".self::block('Poêlée de courgettes');

        self::assertSame(['Poêlée de courgettes'], self::names($this->extractor->extract($text)['recipes']));
    }

    public function testThreeBlocksComeBackInTheirOrder(): void
    {
        $text = implode("\n\n---\n\n", [
            "### 1\n```json\n".self::block('Gratin')."\n```",
            "### 2\n```json\n".self::block('Soupe')."\n```",
            "### 3\n```json\n".self::block('Salade')."\n```",
        ]);

        self::assertSame(['Gratin', 'Soupe', 'Salade'], self::names($this->extractor->extract($text)['recipes']));
    }

    public function testAWrapperObjectAndATopLevelListAreFlattened(): void
    {
        $wrapper = json_encode(['recipes' => [self::recipe('Gratin'), self::recipe('Soupe')]], \JSON_THROW_ON_ERROR);
        $list = json_encode([self::recipe('Salade')], \JSON_THROW_ON_ERROR);

        $result = $this->extractor->extract($wrapper."\n\n".$list);

        self::assertSame(['Gratin', 'Soupe', 'Salade'], self::names($result['recipes']));
    }

    public function testBracesQuotesAndBackslashesInsideStringsDoNotBreakTheBlock(): void
    {
        $recipe = self::recipe('Sauce {maison}');
        $recipe['steps'] = ['Mélanger } puis {', 'Dire "stop" \\ et ] ['];

        $result = $this->extractor->extract("Voici :\n".json_encode($recipe, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));

        self::assertSame(['Sauce {maison}'], self::names($result['recipes']));
        self::assertSame(['Mélanger } puis {', 'Dire "stop" \\ et ] ['], $result['recipes'][0]['steps']);
    }

    /** One stray brace in the prose must not swallow every block after it. */
    public function testAStrayBraceBeforeABlockDoesNotHideIt(): void
    {
        $text = "Astuce { à garder en tête : saler à la fin.\n\n```json\n".self::block('Gratin')."\n```";

        $result = $this->extractor->extract($text);

        self::assertSame(['Gratin'], self::names($result['recipes']));
        self::assertSame([], $result['unreadable']);
    }

    public function testBracesInProseAreIgnoredWithoutComplaint(): void
    {
        $result = $this->extractor->extract('Variante {végétarienne} possible, voir [plus bas](#suite). {"note": "sans recette"}');

        self::assertSame([], $result['recipes']);
        self::assertSame([], $result['unreadable']);
    }

    public function testABlankRecipeNameIsNotARecipe(): void
    {
        $result = $this->extractor->extract(self::block('   '));

        self::assertSame([], $result['recipes']);
        self::assertSame([], $result['unreadable']);
    }

    /** French typography puts non-breaking spaces where JSON only accepts plain ones. */
    public function testNonBreakingSpacesAroundTheJsonAreTolerated(): void
    {
        $block = str_replace(['    ', '":'], ["\u{00A0}\u{00A0}", "\":\u{202F}"], self::block('Gratin'));

        self::assertSame(['Gratin'], self::names($this->extractor->extract("\u{FEFF}".$block)['recipes']));
    }

    public function testACutBlockIsReportedWithItsNameAsTruncated(): void
    {
        $block = self::block('Gratin de pâtes');
        $cut = substr($block, 0, (int) strpos($block, '"steps"') + 20);

        $result = $this->extractor->extract("```json\n".$cut);

        self::assertSame([], $result['recipes']);
        self::assertSame([['recipeName' => 'Gratin de pâtes', 'truncated' => true]], $result['unreadable']);
    }

    /** A malformed block is reported, never repaired: guessing would import something nobody wrote. */
    public function testATrailingCommaIsReportedAsUnreadableNotTruncated(): void
    {
        $block = substr(self::block('Gratin'), 0, -1).',}';

        $result = $this->extractor->extract($block);

        self::assertSame([], $result['recipes']);
        self::assertSame([['recipeName' => 'Gratin', 'truncated' => false]], $result['unreadable']);
    }

    public function testAGoodBlockFollowedByACutOneKeepsTheGoodOne(): void
    {
        $cut = substr(self::block('Soupe'), 0, 60);

        $result = $this->extractor->extract(self::block('Gratin')."\n\n".$cut);

        self::assertSame(['Gratin'], self::names($result['recipes']));
        self::assertSame([['recipeName' => 'Soupe', 'truncated' => true]], $result['unreadable']);
    }

    /**
     * The whole conversation pasted at once: the prompt's example and its data
     * come with the answer. Only the answer's recipe is a recipe.
     */
    public function testThePromptsExampleAndDataAreNotRecipes(): void
    {
        $prompt = "===== DONNÉES =====\n\n".json_encode(['inventory' => [['key' => 'tomate', 'ingredient' => 'Tomates']]], \JSON_THROW_ON_ERROR)
            ."\n\nExemple :\n```json\n".RecipeImportFormat::exampleJson()."\n```";

        $result = $this->extractor->extract($prompt."\n\nRéponse :\n```json\n".self::block('Gratin')."\n```");

        self::assertSame(['Gratin'], self::names($result['recipes']));
        self::assertSame([], $result['unreadable']);
    }

    public function testEmptyTextHasNothing(): void
    {
        self::assertSame(['recipes' => [], 'unreadable' => []], $this->extractor->extract(''));
    }

    /** @return array<string, mixed> */
    private static function recipe(string $name): array
    {
        return [
            'recipeName' => $name,
            'feasibility' => 'ready',
            'servings' => 2,
            'preparationTime' => 20,
            'summary' => 'Simple.',
            'ingredientsFromInventory' => [['key' => 'courgette', 'label' => 'Courgettes', 'quantity' => 400, 'unit' => 'g', 'essential' => true]],
            'steps' => ['Couper.', 'Cuire.'],
            'stockConsumptionProposal' => [['key' => 'courgette', 'label' => 'Courgettes', 'quantity' => 400, 'unit' => 'g']],
        ];
    }

    private static function block(string $name): string
    {
        return json_encode(self::recipe($name), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT);
    }

    /**
     * @param list<array<string, mixed>> $recipes
     *
     * @return list<string>
     */
    private static function names(array $recipes): array
    {
        return array_map(static fn (array $recipe): string => $recipe['recipeName'], $recipes);
    }
}
