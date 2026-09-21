<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Household;
use App\Entity\Member;
use App\Entity\Product;
use App\Enum\ProductSource;
use App\Service\HouseholdAccessKey;
use App\Service\OpenFoodFacts\OpenFoodFactsClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Une panne d'Open Food Facts, vue depuis les deux routes qui l'interrogent.
 *
 * Un 404 disait jusqu'ici « produit inconnu » aussi bien pour un code absent de
 * la base que pour un quota atteint ou un délai dépassé. L'application ajoutait
 * alors une ligne vide pour un produit connu, et sa validation apprenait au
 * catalogue partagé une fiche manuelle — jamais rafraîchie — qui masquait la
 * vraie pour tous les foyers.
 */
class OpenFoodFactsOutageTest extends WebTestCase
{
    use RequiresDatabase;

    private const BARCODE = '3017620422003';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

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

        $household = new Household('Foyer de test');
        $household->setAccessKeyHash($keys->hash($accessKey));
        $member = new Member($household, 'Theo');

        $this->entityManager->persist($household);
        $this->entityManager->persist($member);
        $this->entityManager->flush();

        $this->server = [
            'HTTP_X_HOUSEHOLD' => (string) $household->getId(),
            'HTTP_X_HOUSEHOLD_KEY' => $accessKey,
            'HTTP_X_MEMBER' => (string) $member->getId(),
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

    public function testAnUnknownProductIsStillA404(): void
    {
        $this->openFoodFactsAnswers(404);

        $this->client->request('GET', '/api/products/barcode/'.self::BARCODE, server: $this->server);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->payload()['found']);
    }

    public function testAnOutageWithNothingCachedIsA503(): void
    {
        $this->openFoodFactsAnswers(429);

        $this->client->request('GET', '/api/products/barcode/'.self::BARCODE, server: $this->server);

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString('Open Food Facts', $this->payload()['error']);
    }

    /** Une fiche périmée reste plus utile qu'une panne : elle est servie telle quelle. */
    public function testAnOutageServesTheStaleCachedProduct(): void
    {
        $product = new Product('Pâte à tartiner', 'pate-a-tartiner', ProductSource::OpenFoodFacts);
        $product->setBarcode(self::BARCODE);
        $product->setRefreshedAt(new \DateTimeImmutable('-3 years'));
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        $this->openFoodFactsAnswers(503);

        $this->client->request('GET', '/api/products/barcode/'.self::BARCODE, server: $this->server);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame('Pâte à tartiner', $this->payload()['product']['name']);
    }

    /**
     * Le lot saisi à la main entre quand même au stock : seule la contribution au
     * catalogue partagé attend qu'Open Food Facts ait pu dire s'il connaît le code.
     */
    public function testAnOutageDoesNotTeachTheCatalogAManualProduct(): void
    {
        $this->openFoodFactsAnswers(503);

        $this->createStockItemFromScannedBarcode();

        self::assertNull($this->catalogSourceOf(self::BARCODE));
    }

    /** Le contre-exemple : un code réellement inconnu est toujours appris, sinon il redeviendrait inconnu à chaque scan. */
    public function testAnUnknownBarcodeIsStillLearned(): void
    {
        $this->openFoodFactsAnswers(404);

        $this->createStockItemFromScannedBarcode();

        self::assertSame(ProductSource::Manual->value, $this->catalogSourceOf(self::BARCODE));
    }

    /**
     * Remplace le client avant sa première instanciation : le conteneur de test
     * l'autorise pour un service privé, et chaque test ne fait qu'une requête —
     * le noyau n'est donc pas redémarré entre le remplacement et l'appel.
     */
    private function openFoodFactsAnswers(int $status): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            '{"status":"failure"}',
            ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']],
        ));

        static::getContainer()->set(
            OpenFoodFactsClient::class,
            new OpenFoodFactsClient($http, new NullLogger(), 'https://world.openfoodfacts.org', 'Food/test'),
        );
    }

    private function createStockItemFromScannedBarcode(): void
    {
        $this->client->request('POST', '/api/stock-items', server: $this->server, content: json_encode([
            'barcode' => self::BARCODE,
            'name' => 'Pâte à tartiner',
            'numberOfPackages' => 1,
            'quantityPerPackage' => 400,
            'unit' => 'g',
            'storageLocation' => 'pantry',
            'operationKey' => bin2hex(random_bytes(8)),
        ], \JSON_THROW_ON_ERROR));

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
    }

    private function catalogSourceOf(string $barcode): ?string
    {
        $source = $this->entityManager->getConnection()->fetchOne(
            'SELECT source FROM product WHERE barcode = ?',
            [$barcode],
        );

        return false === $source ? null : (string) $source;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
