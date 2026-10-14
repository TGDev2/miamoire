<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\StockItem;
use App\Enum\FoodCut;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Service\Ai\Generator\RecipeGeneratorFactory;
use App\Service\Ai\Generator\RecipeGeneratorInterface;
use App\Service\HouseholdAccessKey;
use App\Service\Product\VariantIdentity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The answer of a real generation, replayed through `/suggest`: the model
 * "assumed" minced steak to be minced. The screen must only ask about the
 * guess that is one.
 */
class AssumedCutSuggestTest extends WebTestCase
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
        $this->entityManager->persist($this->household);
        $this->entityManager->flush();

        $this->authenticated = [
            'HTTP_X_HOUSEHOLD' => (string) $this->household->getId(),
            'HTTP_X_HOUSEHOLD_KEY' => $accessKey,
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            $this->entityManager->close();
        }

        parent::tearDown();
    }

    public function testOnlyRealGuessesReachTheScreen(): void
    {
        $steak = $this->stock('Steaks hachés', FoodCut::Ground);
        $breast = $this->stock('Blanc de poulet');

        $this->replay([
            ['key' => $steak, 'label' => 'Steaks hachés', 'quantity' => 2, 'unit' => 'piece', 'essential' => true, 'assumedCut' => 'ground'],
            ['key' => $breast, 'label' => 'Blanc de poulet', 'quantity' => 2, 'unit' => 'piece', 'essential' => true, 'assumedCut' => 'escalope'],
        ]);

        $this->client->request('POST', '/api/recipes/suggest', server: $this->authenticated, content: '{}');

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $ingredients = json_decode((string) $response->getContent(), true)['recipes'][0]['ingredientsFromInventory'];

        self::assertSame('none', $ingredients[0]['assumedCut'], 'The steak was known to be minced.');
        self::assertSame('escalope', $ingredients[1]['assumedCut'], 'The breast really was a guess.');
    }

    /** @param list<array<string, mixed>> $ingredients */
    private function replay(array $ingredients): void
    {
        $generator = $this->createMock(RecipeGeneratorInterface::class);
        $generator->method('label')->willReturn('Replay');
        $generator->method('generate')->willReturn(json_encode(['recipes' => [[
            'recipeName' => 'Poêlée du soir',
            'feasibility' => 'ready',
            'servings' => 2,
            'preparationTime' => 20,
            'summary' => 'Test.',
            'foodGroups' => [],
            'includesSideDish' => false,
            'ingredientsFromInventory' => $ingredients,
            'missingIngredients' => [],
            'substitutions' => [],
            'utensils' => ['poêle'],
            'steps' => ['Cuire.'],
            'stockConsumptionProposal' => [],
        ]]], \JSON_THROW_ON_ERROR));

        $factory = $this->createMock(RecipeGeneratorFactory::class);
        $factory->method('create')->willReturn($generator);
        static::getContainer()->set(RecipeGeneratorFactory::class, $factory);
    }

    private function stock(string $name, ?FoodCut $cut = null): string
    {
        $identity = VariantIdentity::of(
            preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)) ?? $name,
            Unit::Piece->family(),
            [],
            $cut,
        );

        $variant = new HouseholdProductVariant($this->household, $name, $identity, $identity->slugBase());
        $this->entityManager->persist($variant);
        $this->entityManager->persist(new StockItem($this->household, $variant, 4, Unit::Piece, StorageLocation::Fridge));
        $this->entityManager->flush();

        return $variant->getSlug();
    }
}
