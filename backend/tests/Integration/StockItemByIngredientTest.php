<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\Member;
use App\Entity\StockItem;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Service\HouseholdAccessKey;
use App\Service\Product\VariantIdentity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /api/stock-items/by-ingredient/{key}` — ce que l'écran d'une recette
 * appelle quand on touche un ingrédient pour ouvrir sa fiche.
 *
 * La clé d'une recette désigne une variante précise, jamais un simple libellé
 * (voir `RecipeSuggestionService`) : ce test vérifie donc la résolution par
 * slug exact, le choix FEFO quand plusieurs lots existent, et les deux 404
 * distincts (variante absente, variante sans lot restant) plutôt qu'une
 * réponse vide silencieuse.
 */
class StockItemByIngredientTest extends WebTestCase
{
    use RequiresDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Household $household;

    /** @var array<string, string> */
    private array $server = [];

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
        $member = new Member($this->household, 'Theo');

        $this->entityManager->persist($this->household);
        $this->entityManager->persist($member);
        $this->entityManager->flush();

        $this->server = [
            'HTTP_X_HOUSEHOLD' => (string) $this->household->getId(),
            'HTTP_X_HOUSEHOLD_KEY' => $accessKey,
            'HTTP_X_MEMBER' => (string) $member->getId(),
        ];
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            $this->entityManager->close();
        }

        parent::tearDown();
    }

    public function testResolvesTheOnlyAvailableLot(): void
    {
        $variant = $this->makeVariant('tomates');
        $lot = $this->makeLot($variant, 500);
        $this->entityManager->flush();

        $this->client->request('GET', '/api/stock-items/by-ingredient/tomates', server: $this->server);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(['id' => (string) $lot->getId()], $this->decode());
    }

    /** Le lot qui périme le plus tôt est celui qu'on ouvre — même règle que la déduction de stock. */
    public function testResolvesTheLotExpiringSoonestWhenSeveralExist(): void
    {
        $variant = $this->makeVariant('tomates');
        $this->makeLot($variant, 500)->setExpirationDate(new \DateTimeImmutable('+10 days'));
        $urgent = $this->makeLot($variant, 200);
        $urgent->setExpirationDate(new \DateTimeImmutable('+1 day'));
        $this->entityManager->flush();

        $this->client->request('GET', '/api/stock-items/by-ingredient/tomates', server: $this->server);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(['id' => (string) $urgent->getId()], $this->decode());
    }

    public function testUnknownIngredientKeyReturnsNotFound(): void
    {
        $this->client->request('GET', '/api/stock-items/by-ingredient/inconnu', server: $this->server);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    /** La variante existe, mais son dernier lot a déjà été entièrement consommé. */
    public function testAVariantWithoutAnyRemainingLotReturnsNotFound(): void
    {
        $variant = $this->makeVariant('tomates');
        $this->makeLot($variant, 0);
        $this->entityManager->flush();

        $this->client->request('GET', '/api/stock-items/by-ingredient/tomates', server: $this->server);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    private function makeVariant(string $ingredientKey): HouseholdProductVariant
    {
        $identity = VariantIdentity::of($ingredientKey, Unit::Gram->family(), []);
        $variant = new HouseholdProductVariant($this->household, ucfirst($ingredientKey), $identity, $identity->slugBase());

        $this->entityManager->persist($variant);

        return $variant;
    }

    private function makeLot(HouseholdProductVariant $variant, float $quantity): StockItem
    {
        $lot = new StockItem($this->household, $variant, $quantity, Unit::Gram, StorageLocation::Pantry);
        $this->entityManager->persist($lot);

        return $lot;
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertIsArray($payload);

        return $payload;
    }
}
