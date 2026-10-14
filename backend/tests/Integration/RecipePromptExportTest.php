<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
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
 * L'export de la consigne, pour la confier à la main à un autre assistant.
 *
 * Il sert précisément quand le générateur ne répond pas : il ne doit donc
 * jamais en dépendre. La fabrique est remplacée par un double qui échoue à la
 * moindre sollicitation — une clé d'API présente dans l'environnement ne peut
 * pas faire passer ce test par accident.
 */
class RecipePromptExportTest extends WebTestCase
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

    public function testTheExportCarriesTheInventoryAndTheInstructionWithoutAnyGenerator(): void
    {
        $this->stock('Courgettes', 600.0);
        $this->stock('Saumon fumé', 200.0);

        $prompt = $this->exportedPrompt(['people' => 3, 'foodGroups' => ['vegetable']]);

        self::assertStringContainsString('Courgettes', $prompt);
        self::assertStringContainsString('"people":3', $prompt);
        self::assertStringContainsString('COMPOSITION DEMANDÉE', $prompt);
        self::assertStringContainsString('Règles impératives', $prompt);
        self::assertStringContainsString('FORMAT DE RÉPONSE', $prompt);
        // Le contrôle « avant » tient aussi pour l'export : l'aliment exclu ne part pas.
        self::assertStringNotContainsString('Saumon fumé', $prompt);
        // La consigne JSON est celle du générateur : un humain lit la réponse ici.
        self::assertStringNotContainsString('Réponds uniquement par le JSON', $prompt);
    }

    /**
     * Un aliment dont la date est passée reste dans le placard : l'export le
     * transmet, et demande qu'il soit employé plutôt qu'écarté.
     */
    public function testTheExportKeepsExpiredFoodAndInsistsOnUsingIt(): void
    {
        $this->stock('Saucisses de Toulouse', 500.0, '-10 days');
        $this->stock('Oignons émincés', 300.0, '+300 days');
        // À consommer jusqu'à aujourd'hui : pas encore périmé.
        $this->stock('Yaourts nature', 500.0, 'today');

        // Les retours à la ligne de la consigne ne font pas partie de ce qu'elle dit.
        $prompt = (string) preg_replace('/\s+/u', ' ', $this->exportedPrompt([]));

        self::assertStringContainsString('Saucisses de Toulouse', $prompt);
        // Le serveur dit lui-même ce qui est périmé : comparé par le modèle, un
        // 2027 lointain s'est déjà vu annoncé « date dépassée ».
        self::assertSame(1, substr_count($prompt, '"expired":true'));
        self::assertMatchesRegularExpression(
            '/"useBy":"'.(new \DateTimeImmutable('-10 days'))->format('Y-m-d').'"[^}]*"expired":true/',
            $prompt,
        );
        self::assertStringContainsString('Une date dépassée n\'écarte jamais un aliment', $prompt);
        self::assertStringContainsString('Aliments à date dépassée non utilisés', $prompt);
    }

    /**
     * In the freezer, a passed date is not an expiry: the export must not
     * flag it "date dépassée", only say that the food is frozen.
     */
    public function testAFrozenFoodWithAPassedDateIsNotFlaggedExpired(): void
    {
        $this->stock('Andouillettes', 500.0, '-120 days', StorageLocation::Freezer);
        $this->stock('Saucisses de Toulouse', 500.0, '-10 days');

        $prompt = (string) preg_replace('/\s+/u', ' ', $this->exportedPrompt([]));

        self::assertSame(1, substr_count($prompt, '"expired":true'), 'Only the pantry sausages are expired.');
        self::assertSame(1, substr_count($prompt, '"frozen":true'));
        self::assertStringContainsString('Une part `frozen` est au congélateur', $prompt);
    }

    public function testTheExportLeavesTheAntiRepetitionHistoryUntouched(): void
    {
        $this->stock('Courgettes', 600.0);

        $prompt = $this->exportedPrompt([]);

        self::assertStringContainsString('Gratin de courgettes', $prompt);

        $this->entityManager->clear();
        $household = $this->entityManager->find(Household::class, $this->household->getId());
        self::assertSame(['Gratin de courgettes'], $household?->getRecentRecipeSuggestions());
    }

    public function testAnEmptyInventoryIsRefusedAsForAGeneration(): void
    {
        $this->client->request('POST', '/api/recipes/prompt', server: $this->authenticated, content: '{}');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        // Le foyer a une exclusion : c'est le message de `suggest()` dans ce cas.
        self::assertStringContainsString('Aucun aliment de votre inventaire', (string) $this->client->getResponse()->getContent());
    }

    public function testTheExportNeedsAHousehold(): void
    {
        $this->client->request('POST', '/api/recipes/prompt', content: '{}');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testTheExportNeedsTheHouseholdKey(): void
    {
        $this->stock('Courgettes', 600.0);

        $this->client->request('POST', '/api/recipes/prompt', server: [
            'HTTP_X_HOUSEHOLD' => $this->authenticated['HTTP_X_HOUSEHOLD'],
        ], content: '{}');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertStringNotContainsString('Courgettes', (string) $this->client->getResponse()->getContent());
    }

    /** @param array<string, mixed> $body */
    private function exportedPrompt(array $body): string
    {
        $this->client->request(
            'POST',
            '/api/recipes/prompt',
            server: $this->authenticated,
            content: json_encode((object) $body, \JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{prompt: string} $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded['prompt'];
    }

    private function stock(
        string $name,
        float $grams,
        ?string $expiresIn = null,
        StorageLocation $location = StorageLocation::Pantry,
    ): void {
        $identity = VariantIdentity::of(
            preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)) ?? $name,
            Unit::Gram->family(),
            [],
            null,
        );

        $variant = new HouseholdProductVariant($this->household, $name, $identity, $identity->slugBase());
        $this->entityManager->persist($variant);
        $item = new StockItem($this->household, $variant, $grams, Unit::Gram, $location);

        if (null !== $expiresIn) {
            $item->setExpirationDate(new \DateTimeImmutable($expiresIn));
        }

        $this->entityManager->persist($item);
        $this->entityManager->flush();
    }
}
