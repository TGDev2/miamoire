<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Household;
use App\Entity\Member;
use App\Service\HouseholdAccessKey;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The freezer as the app sees it: every way into the freezer keeps the
 * printed date, and every one of them changes how that date is judged.
 */
class FreezerApiTest extends WebTestCase
{
    use RequiresDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
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
        $household = new Household('Foyer de test');
        $household->setAccessKeyHash($keys->hash($accessKey));
        $member = new Member($household, 'Theo');

        $this->entityManager->persist($household);
        $this->entityManager->persist($member);
        $this->entityManager->flush();

        $this->authenticated = [
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

    /** The real household's case: sausages put away in the freezer, date long passed. */
    public function testALotAddedToTheFreezerWithAPassedDateIsNotExpired(): void
    {
        $lot = $this->create('Saucisses de Toulouse', 'freezer', expiresInDays: -30);

        self::assertSame($this->inDays(-30), $lot['expirationDate'], 'The printed date is kept as printed.');
        self::assertFalse($lot['expirationIsEstimated']);
        self::assertSame('frozen', $lot['effectiveUseByBasis']);
        self::assertNotSame('expired', $lot['status']);
        self::assertNull($lot['frozenAt'], 'Added straight into the freezer: when it was frozen is unknown.');
    }

    /** The "Congelé" button used to overwrite the printed date with now + 180 days. */
    public function testTheFreezeButtonKeepsThePrintedDate(): void
    {
        $lot = $this->create('Blanc de poulet', 'fridge', expiresInDays: 1);

        $frozen = $this->send('POST', sprintf('/api/stock-items/%s/freeze', $lot['id']), []);

        self::assertSame('freezer', $frozen['storageLocation']);
        self::assertSame($this->inDays(1), $frozen['expirationDate']);
        self::assertFalse($frozen['expirationIsEstimated']);
        self::assertNotNull($frozen['frozenAt']);
        self::assertSame('frozen', $frozen['effectiveUseByBasis']);
    }

    /** Moving a lot with the location chips is freezing too, and leaving the freezer is thawing. */
    public function testTheLocationChipsFreezeAndThaw(): void
    {
        $lot = $this->create('Blanc de poulet', 'fridge', expiresInDays: 1);

        $frozen = $this->send('PATCH', sprintf('/api/stock-items/%s', $lot['id']), ['storageLocation' => 'freezer']);
        self::assertNotNull($frozen['frozenAt']);
        self::assertSame($this->inDays(1), $frozen['expirationDate']);

        $thawed = $this->send('PATCH', sprintf('/api/stock-items/%s', $lot['id']), ['storageLocation' => 'fridge']);
        self::assertNotNull($thawed['thawedAt']);
        self::assertSame('thawed', $thawed['effectiveUseByBasis']);
        self::assertSame(1, $thawed['daysUntilEffectiveUseBy'], 'Thawed: eaten within 24 hours.');
        self::assertSame('expiring_soon', $thawed['status']);
    }

    /** Freezing a lot whose date has already passed does not make it safe again. */
    public function testFreezingALotAfterItsDateKeepsItExpired(): void
    {
        $lot = $this->create('Blanc de poulet', 'fridge', expiresInDays: -2);

        $frozen = $this->send('POST', sprintf('/api/stock-items/%s/freeze', $lot['id']), []);

        self::assertSame('freezer', $frozen['storageLocation']);
        self::assertSame('expired', $frozen['status']);
        self::assertSame('printed', $frozen['effectiveUseByBasis']);
    }

    /** @return array<string, mixed> */
    private function create(string $name, string $location, int $expiresInDays): array
    {
        $this->client->request(
            'POST',
            '/api/stock-items',
            server: $this->authenticated,
            content: json_encode([
                'name' => $name,
                'unit' => 'g',
                'quantityPerPackage' => 500,
                'storageLocation' => $location,
                'expirationDate' => $this->inDays($expiresInDays),
                'operationKey' => bin2hex(random_bytes(8)),
            ], \JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true)['items'][0];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function send(string $method, string $uri, array $body): array
    {
        $this->client->request(
            $method,
            $uri,
            server: $this->authenticated,
            content: json_encode($body + ['operationKey' => bin2hex(random_bytes(8))], \JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true);
    }

    private function inDays(int $days): string
    {
        return (new \DateTimeImmutable('today'))->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }
}
