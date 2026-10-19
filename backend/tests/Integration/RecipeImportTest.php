<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\Member;
use App\Entity\SavedRecipe;
use App\Entity\StockItem;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Service\Ai\Generator\RecipeGeneratorFactory;
use App\Service\HouseholdAccessKey;
use App\Service\Product\VariantIdentity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * A recipe brought back from another assistant, through the front door.
 *
 * The import only reads: it finds the recipes in the pasted text, ties their
 * lines to the household's foods, refuses what breaks an exclusion and plans
 * the rest against the stock. Saving goes through the route every recipe
 * uses, which is what makes an imported recipe one like the others.
 */
class RecipeImportTest extends WebTestCase
{
    use RequiresDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Household $household;
    private Member $theo;
    private Member $lea;
    /** @var array<string, string> */
    private array $authenticated;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;

        $this->skipUnlessDatabaseIsReachable($this->entityManager);
        $this->purgeDatabase($this->entityManager);

        /** @var HouseholdAccessKey $keys */
        $keys = static::getContainer()->get(HouseholdAccessKey::class);

        $accessKey = $keys->generate();
        $this->household = new Household('Foyer de test');
        $this->household->setAccessKeyHash($keys->hash($accessKey));
        $this->household->rememberRecipeSuggestions(['Gratin de courgettes']);
        $this->theo = new Member($this->household, 'Theo');
        $this->lea = new Member($this->household, 'Léa');
        $this->entityManager->persist($this->household);
        $this->entityManager->persist($this->theo);
        $this->entityManager->persist($this->lea);
        $this->entityManager->flush();

        $this->authenticated = [
            'HTTP_X_HOUSEHOLD' => (string) $this->household->getId(),
            'HTTP_X_HOUSEHOLD_KEY' => $accessKey,
            'HTTP_X_MEMBER' => (string) $this->theo->getId(),
            'CONTENT_TYPE' => 'application/json',
        ];

        // Importing never needs a generator: the other assistant already answered.
        $factory = $this->createMock(RecipeGeneratorFactory::class);
        $factory->expects(self::never())->method('create');
        static::getContainer()->set(RecipeGeneratorFactory::class, $factory);
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            $this->entityManager->close();
        }

        parent::tearDown();
    }

    public function testTheImportNeedsAHousehold(): void
    {
        $this->client->request('POST', '/api/recipes/import', content: json_encode(['text' => 'x'], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testTheImportNeedsTheHouseholdKey(): void
    {
        $this->client->request('POST', '/api/recipes/import', server: [
            'HTTP_X_HOUSEHOLD' => $this->authenticated['HTTP_X_HOUSEHOLD'],
        ], content: json_encode(['text' => $this->block('Gratin', [])], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testATextIsRequiredAndBounded(): void
    {
        $this->client->request('POST', '/api/recipes/import', server: $this->authenticated, content: '{}');
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $this->client->request('POST', '/api/recipes/import', server: $this->authenticated, content: json_encode([
            'text' => str_repeat('a', 100_001),
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testATextWithoutAnyRecipeSaysWhatToCopy(): void
    {
        $response = $this->import("Voici trois idées : un gratin, une soupe, une salade.\n\nBon appétit !", Response::HTTP_UNPROCESSABLE_ENTITY);

        self::assertStringContainsString('bloc d\'import', $response['error']);
    }

    public function testACutBlockIsNamedSoTheUserCanCopyItAgain(): void
    {
        $block = $this->block('Gratin de pâtes', []);

        $response = $this->import(substr($block, 0, (int) strpos($block, '"steps"')), Response::HTTP_UNPROCESSABLE_ENTITY);

        self::assertStringContainsString('Gratin de pâtes', $response['error']);
        self::assertStringContainsString('coupé', $response['error']);
    }

    public function testRecipesInProseComeBackPlannedAndNothingIsWritten(): void
    {
        $courgettes = $this->stock('Courgettes', 600.0);
        $riz = $this->stock('Riz', 1000.0);

        $text = "## Poêlée\n\nRapide.\n\n```json\n"
            .$this->block('Poêlée de courgettes', [[$courgettes, 'Courgettes', 400, 'g']])
            ."\n```\n\n## Riz sauté\n\n```json\n"
            .$this->block('Riz sauté', [[$riz, 'Riz', 0.2, 'kg']])
            ."\n```";

        $response = $this->import($text);

        self::assertSame(['Poêlée de courgettes', 'Riz sauté'], array_map(
            static fn (array $imported): string => $imported['recipe']['recipeName'],
            $response['recipes'],
        ));
        self::assertSame('ready', $response['recipes'][0]['recipe']['plan']['status']);
        self::assertSame([], $response['recipes'][0]['notices']);
        self::assertSame([], $response['rejected']);

        $this->entityManager->clear();
        self::assertSame(0, $this->entityManager->getRepository(SavedRecipe::class)->count([]));
        self::assertSame(600.0, $this->remaining($courgettes));
        self::assertSame(
            ['Gratin de courgettes'],
            $this->entityManager->find(Household::class, $this->household->getId())?->getRecentRecipeSuggestions(),
        );
    }

    public function testAnExcludedFoodAnywhereInTheRecipeIsRefused(): void
    {
        $this->exclude('arachide');
        $courgettes = $this->stock('Courgettes', 600.0);

        $recipe = $this->recipe('Poêlée de courgettes', [[$courgettes, 'Courgettes', 400, 'g']]);
        $recipe['steps'][] = 'Napper de beurre de cacahuète.';

        $response = $this->import(json_encode($recipe, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));

        self::assertSame([], $response['recipes']);
        self::assertSame('Poêlée de courgettes', $response['rejected'][0]['recipeName']);
        self::assertStringContainsString('dans l\'étape 3', $response['rejected'][0]['reason']);
    }

    /** A recipe can call the stock's peanut butter anything: its key says what it is. */
    public function testAnExcludedFoodCitedOnlyByItsKeyIsRefused(): void
    {
        $this->exclude('arachide');
        $peanut = $this->stock('Beurre de cacahuète', 350.0);

        $recipe = $this->recipe('Tartines', []);
        $recipe['ingredientsFromInventory'] = [['key' => $peanut, 'label' => 'Pâte à tartiner', 'quantity' => 30, 'unit' => 'g', 'essential' => true]];

        $response = $this->import(json_encode($recipe, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));

        self::assertSame([], $response['recipes']);
        self::assertStringContainsString('dans les ingrédients', $response['rejected'][0]['reason']);
    }

    public function testCoconutMilkIsNotMilk(): void
    {
        $this->exclude('lait');
        $coco = $this->stock('Lait de coco', 400.0, Unit::Milliliter);

        $response = $this->import($this->block('Curry coco', [[$coco, 'Lait de coco', 200, 'ml']]));

        self::assertSame([], $response['rejected']);
        self::assertSame('Curry coco', $response['recipes'][0]['recipe']['recipeName']);
    }

    /** Kept, the line would make "Retirer du stock" fail for the whole recipe. */
    public function testADeductionInTheWrongUnitIsDroppedAndSaid(): void
    {
        $eggs = $this->stock('Oeufs', 6.0, Unit::Piece);

        $response = $this->import($this->block('Omelette', [[$eggs, 'Oeufs', 120, 'g']]));

        $imported = $response['recipes'][0];
        self::assertSame([], $imported['recipe']['stockConsumptionProposal']);
        self::assertSame(['« Oeufs » se compte à l\'unité dans votre stock, pas en g : il ne sera pas retiré.'], $imported['notices']);
        self::assertSame('ready', $imported['recipe']['plan']['status'], 'A dropped line is not a missing food.');
    }

    /** The whole conversation pasted at once: the prompt's example is not a recipe. */
    public function testThePromptPastedWithTheAnswerOnlyYieldsTheAnswer(): void
    {
        $courgettes = $this->stock('Courgettes', 600.0);

        $this->client->request('POST', '/api/recipes/prompt', server: $this->authenticated, content: '{}');
        self::assertResponseIsSuccessful();
        /** @var array{prompt: string} $export */
        $export = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $response = $this->import($export['prompt']."\n\n```json\n".$this->block('Poêlée', [[$courgettes, 'Courgettes', 400, 'g']])."\n```");

        self::assertCount(1, $response['recipes']);
        self::assertSame('Poêlée', $response['recipes'][0]['recipe']['recipeName']);
    }

    /**
     * Saved, an imported recipe is one like the others: the whole household
     * sees it, it is saved once, and cooking it deducts the tied food.
     */
    public function testTheImportedRecipeIsSavedSharedAndCooked(): void
    {
        $courgettes = $this->stock('Courgettes', 600.0);
        $imported = $this->import($this->block('Poêlée de courgettes', [[$courgettes, 'courgettes', 400, 'g']]))['recipes'][0]['recipe'];

        $saved = $this->save($imported, Response::HTTP_CREATED);
        self::assertSame($saved['id'], $this->save($imported, Response::HTTP_OK)['id'], 'Saving twice keeps one recipe.');

        $this->client->request('GET', '/api/recipes/saved', server: [
            ...$this->authenticated,
            'HTTP_X_MEMBER' => (string) $this->lea->getId(),
        ]);
        self::assertResponseIsSuccessful();
        /** @var array{recipes: list<array{id: string, savedBy: ?string}>} $list */
        $list = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame([[$saved['id'], 'Theo']], array_map(static fn (array $entry): array => [$entry['id'], $entry['savedBy']], $list['recipes']));

        $this->client->request('POST', '/api/recipes/confirm', server: $this->authenticated, content: json_encode([
            'operationKey' => 'import-test-1',
            'recipeName' => $imported['recipeName'],
            'savedRecipeId' => $saved['id'],
            'stockConsumptionProposal' => $imported['stockConsumptionProposal'],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        $this->entityManager->clear();
        self::assertSame(200.0, $this->remaining($courgettes));
    }

    /**
     * @param list<array{string, string, float|int, string}> $deductions key, label, quantity, unit
     *
     * @return array<string, mixed>
     */
    private function recipe(string $name, array $deductions): array
    {
        return [
            'recipeName' => $name,
            'feasibility' => 'ready',
            'servings' => 2,
            'preparationTime' => 20,
            'summary' => 'Simple et rapide.',
            'includesSideDish' => false,
            'utensils' => ['poêle'],
            'ingredientsFromInventory' => array_map(
                static fn (array $line): array => ['key' => $line[0], 'label' => $line[1], 'quantity' => $line[2], 'unit' => $line[3], 'essential' => true],
                $deductions,
            ),
            'missingIngredients' => [],
            'substitutions' => [],
            'steps' => ['Préparer.', 'Cuire.'],
            'stockConsumptionProposal' => array_map(
                static fn (array $line): array => ['key' => $line[0], 'label' => $line[1], 'quantity' => $line[2], 'unit' => $line[3]],
                $deductions,
            ),
        ];
    }

    /** @param list<array{string, string, float|int, string}> $deductions */
    private function block(string $name, array $deductions): string
    {
        return json_encode($this->recipe($name, $deductions), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT);
    }

    /** @return array<string, mixed> */
    private function import(string $text, int $status = Response::HTTP_OK): array
    {
        $this->client->request('POST', '/api/recipes/import', server: $this->authenticated, content: json_encode([
            'text' => $text,
        ], \JSON_THROW_ON_ERROR));

        $response = $this->client->getResponse();
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $recipe
     *
     * @return array{id: string}
     */
    private function save(array $recipe, int $status): array
    {
        $this->client->request('POST', '/api/recipes/saved', server: $this->authenticated, content: json_encode([
            'recipe' => $recipe,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame($status);

        /** @var array{id: string} $saved */
        $saved = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $saved;
    }

    private function exclude(string $label): void
    {
        $this->household->setDietaryExclusions([['label' => $label, 'severity' => 'preference']]);
        $this->entityManager->flush();
    }

    /** The variant's slug — the key the exported data carries. */
    private function stock(string $name, float $quantity, Unit $unit = Unit::Gram): string
    {
        $identity = VariantIdentity::of(
            preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)) ?? $name,
            $unit->family(),
            [],
            null,
        );

        $variant = new HouseholdProductVariant($this->household, $name, $identity, $identity->slugBase());
        $this->entityManager->persist($variant);
        $this->entityManager->persist(new StockItem($this->household, $variant, $quantity, $unit, StorageLocation::Pantry));
        $this->entityManager->flush();

        return $variant->getSlug();
    }

    private function remaining(string $slug): float
    {
        $variant = $this->entityManager->getRepository(HouseholdProductVariant::class)->findOneBy(['household' => $this->household->getId(), 'slug' => $slug]);
        $item = $this->entityManager->getRepository(StockItem::class)->findOneBy(['variant' => $variant]);

        return (float) $item?->getRemainingQuantity();
    }
}
