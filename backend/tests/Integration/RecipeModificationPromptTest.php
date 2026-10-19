<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\SavedRecipe;
use App\Entity\StockItem;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Service\Ai\Generator\RecipeGeneratorFactory;
use App\Service\Ai\Import\RecipeImportFormat;
use App\Service\HouseholdAccessKey;
use App\Service\Product\VariantIdentity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * One recipe sent to another assistant to be changed, then brought back
 * through the same import as any of its recipes.
 */
class RecipeModificationPromptTest extends WebTestCase
{
    use RequiresDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Household $household;
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
        $this->household->setDietaryExclusions([['label' => 'saumon', 'severity' => 'preference']]);
        $this->household->rememberRecipeSuggestions(['Gratin de courgettes']);
        $this->entityManager->persist($this->household);
        $this->entityManager->flush();

        $this->authenticated = [
            'HTTP_X_HOUSEHOLD' => (string) $this->household->getId(),
            'HTTP_X_HOUSEHOLD_KEY' => $accessKey,
            'CONTENT_TYPE' => 'application/json',
        ];

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

    public function testASavedRecipeGoesOutWithTheStockAndTheImportRules(): void
    {
        $courgettes = $this->stock('Courgettes', 600.0);
        $this->stock('Saumon fumé', 200.0);
        $savedId = $this->save($this->recipe($courgettes));

        $prompt = $this->prompt(['savedRecipeId' => $savedId]);

        self::assertStringContainsString('"recipeName":"Poêlée de courgettes"', $prompt);
        self::assertStringContainsString('"key":"'.$courgettes.'"', $prompt);
        self::assertStringContainsString(RecipeImportFormat::exampleJson(), $prompt);
        self::assertStringContainsString('MA DEMANDE DE MODIFICATION', $prompt);
        self::assertStringContainsString('une recette qui en enfreint une sera refusée', (string) preg_replace('/\s+/u', ' ', $prompt));
        // The same "before" control as the export: the excluded food does not leave.
        self::assertStringNotContainsString('Saumon fumé', $prompt);
        // The request is what the user types next, in the other assistant.
        self::assertStringEndsWith('MA DEMANDE DE MODIFICATION :', rtrim($prompt));
    }

    /** Pasted back together with the answer, the original is not a second recipe. */
    public function testThePromptPastedWithTheAnswerImportsOnlyTheChangedRecipe(): void
    {
        $courgettes = $this->stock('Courgettes', 600.0);
        $prompt = $this->prompt(['recipe' => $this->recipe($courgettes)]);

        $changed = ['recipeName' => 'Poêlée de courgettes au cumin'] + $this->recipe($courgettes);
        $this->client->request('POST', '/api/recipes/import', server: $this->authenticated, content: json_encode([
            'text' => $prompt." remplace l'ail par du cumin\n\n```json\n".json_encode($changed, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE)."\n```",
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        /** @var array{recipes: list<array{recipe: array{recipeName: string}}>} $imported */
        $imported = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['Poêlée de courgettes au cumin'], array_map(
            static fn (array $entry): string => $entry['recipe']['recipeName'],
            $imported['recipes'],
        ));
    }

    /** A proposal not saved yet, or a meal-plan snapshot, goes out as it is. */
    public function testARecipeNotSavedGoesOutAsSent(): void
    {
        $courgettes = $this->stock('Courgettes', 600.0);

        $prompt = $this->prompt(['recipe' => $this->recipe($courgettes)]);

        self::assertStringContainsString('"recipeName":"Poêlée de courgettes"', $prompt);
    }

    /** Changing a recipe may be the very way to cook without what is missing. */
    public function testAnEmptyStockDoesNotPreventAModification(): void
    {
        $prompt = $this->prompt(['recipe' => $this->recipe('courgettes')]);

        self::assertStringContainsString('"inventory":[]', $prompt);
    }

    public function testNothingIsWritten(): void
    {
        $courgettes = $this->stock('Courgettes', 600.0);
        $savedId = $this->save($this->recipe($courgettes));

        $this->prompt(['savedRecipeId' => $savedId]);

        $this->entityManager->clear();
        self::assertSame(1, $this->entityManager->getRepository(SavedRecipe::class)->count([]));
        self::assertSame(
            ['Gratin de courgettes'],
            $this->entityManager->find(Household::class, $this->household->getId())?->getRecentRecipeSuggestions(),
        );
    }

    public function testARecipeOfAnotherHouseholdOrNoRecipeIsRefused(): void
    {
        $this->client->request('POST', '/api/recipes/modification-prompt', server: $this->authenticated, content: json_encode([
            'savedRecipeId' => '0192a3b4-0000-7000-8000-000000000000',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->client->request('POST', '/api/recipes/modification-prompt', server: $this->authenticated, content: '{}');
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testTheModificationNeedsAHousehold(): void
    {
        $this->client->request('POST', '/api/recipes/modification-prompt', content: '{}');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testTheModificationNeedsTheHouseholdKey(): void
    {
        $this->client->request('POST', '/api/recipes/modification-prompt', server: [
            'HTTP_X_HOUSEHOLD' => $this->authenticated['HTTP_X_HOUSEHOLD'],
        ], content: json_encode(['recipe' => $this->recipe('courgettes')], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** @return array<string, mixed> */
    private function recipe(string $key): array
    {
        return [
            'recipeName' => 'Poêlée de courgettes',
            'feasibility' => 'ready',
            'servings' => 2,
            'preparationTime' => 20,
            'summary' => 'Rapide.',
            'ingredientsFromInventory' => [['key' => $key, 'label' => 'Courgettes', 'quantity' => 400, 'unit' => 'g', 'essential' => true]],
            'steps' => ['Couper.', 'Poêler.'],
            'stockConsumptionProposal' => [['key' => $key, 'label' => 'Courgettes', 'quantity' => 400, 'unit' => 'g']],
        ];
    }

    /** @param array<string, mixed> $recipe */
    private function save(array $recipe): string
    {
        $this->client->request('POST', '/api/recipes/saved', server: $this->authenticated, content: json_encode([
            'recipe' => $recipe,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        /** @var array{id: string} $saved */
        $saved = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $saved['id'];
    }

    /** @param array<string, mixed> $body */
    private function prompt(array $body): string
    {
        $this->client->request('POST', '/api/recipes/modification-prompt', server: $this->authenticated, content: json_encode($body, \JSON_THROW_ON_ERROR));

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{prompt: string} $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded['prompt'];
    }

    /** The variant's slug — the key the exported data carries. */
    private function stock(string $name, float $grams): string
    {
        $identity = VariantIdentity::of(
            preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)) ?? $name,
            Unit::Gram->family(),
            [],
            null,
        );

        $variant = new HouseholdProductVariant($this->household, $name, $identity, $identity->slugBase());
        $this->entityManager->persist($variant);
        $this->entityManager->persist(new StockItem($this->household, $variant, $grams, Unit::Gram, StorageLocation::Pantry));
        $this->entityManager->flush();

        return $variant->getSlug();
    }
}
