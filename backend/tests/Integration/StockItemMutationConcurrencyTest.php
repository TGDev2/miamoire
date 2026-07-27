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
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le même protocole que la confirmation de recette, mais pour les mutations
 * ordinaires — consommer, corriger — qui n'avaient jusqu'ici ni clé
 * d'opération, ni verrou : deux téléphones lisant 500 g puis retirant chacun
 * une quantité pouvaient écrire deux soldes concurrents.
 *
 * Ces trois défauts ne se voient qu'en vraie base, pour les mêmes raisons que
 * `RecipeConfirmationConcurrencyTest` : l'unicité de la clé est portée par un
 * index, le verrou par PostgreSQL, la révision par la colonne `#[ORM\Version]`.
 */
class StockItemMutationConcurrencyTest extends WebTestCase
{
    use RequiresDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Household $household;
    private Member $member;
    private HouseholdProductVariant $variant;
    private StockItem $lot;

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
        $this->member = new Member($this->household, 'Theo');

        $identity = VariantIdentity::of('tomates', Unit::Gram->family(), []);
        $this->variant = new HouseholdProductVariant($this->household, 'Tomates', $identity, $identity->slugBase());

        $this->lot = new StockItem($this->household, $this->variant, 500, Unit::Gram, StorageLocation::Pantry);

        $this->entityManager->persist($this->household);
        $this->entityManager->persist($this->member);
        $this->entityManager->persist($this->variant);
        $this->entityManager->persist($this->lot);
        $this->entityManager->flush();

        $this->server = [
            'HTTP_X_HOUSEHOLD' => (string) $this->household->getId(),
            'HTTP_X_HOUSEHOLD_KEY' => $accessKey,
            'HTTP_X_MEMBER' => (string) $this->member->getId(),
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    /** @var array<string, string> */
    private array $server = [];

    protected function tearDown(): void
    {
        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            $this->entityManager->close();
        }

        parent::tearDown();
    }

    /**
     * Le défaut d'origine, hors recette cette fois : une réponse réseau perdue,
     * une nouvelle tentative, et le stock était retiré deux fois.
     */
    public function testReplayingTheSameOperationKeyOnConsumeAppliesOnce(): void
    {
        $body = json_encode(['operationKey' => 'consume-key-aaaa1111', 'mode' => 'quantity', 'quantity' => 150, 'unit' => 'g']);

        $this->client->request('POST', $this->path('consume'), server: $this->server, content: $body);
        $first = $this->decode();
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertArrayNotHasKey('replayed', $first);

        $this->client->request('POST', $this->path('consume'), server: $this->server, content: $body);
        $second = $this->decode();

        self::assertTrue($second['replayed'] ?? false, 'Le serveur doit reconnaître le geste, pas le refaire.');
        self::assertSame(350.0, $this->remainingInDatabase());
    }

    /**
     * La mise à jour perdue — ce que le verrou existe pour empêcher. Un autre
     * processus verrouille le lot, le tient, puis descend la quantité de 500 à
     * 50 g et valide. Pendant ce temps, une consommation de 30 g arrive par HTTP.
     *
     * Sans le verrou pris avant lecture, elle aurait planifié sur les 500 g lus
     * avant l'attente et écrit 470 g par-dessus les 50 g de l'autre process —
     * 420 g apparus de nulle part. Avec le verrou, elle attend, relit 50 g, et
     * n'en retire que ce qu'il en reste.
     */
    public function testConsumingWhileAnotherProcessChangesTheLotDoesNotLoseTheUpdate(): void
    {
        $other = $this->spawnConcurrentConsumer((string) $this->lot->getId(), 50.0, 700);

        // Le temps que l'autre processus prenne le verrou avant nous.
        usleep(250_000);

        $body = json_encode(['operationKey' => 'consume-key-bbbb2222', 'mode' => 'quantity', 'quantity' => 30, 'unit' => 'g']);
        $this->client->request('POST', $this->path('consume'), server: $this->server, content: $body);

        $this->waitFor($other);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(
            20.0,
            $this->remainingInDatabase(),
            'La quantité écrite par l\'autre processus (50) doit survivre, moins ce que la requête a retiré (30).',
        );
    }

    /**
     * Une correction absolue fondée sur une lecture périmée ne doit pas écraser
     * silencieusement un changement qu'elle n'a jamais vu.
     */
    public function testCorrectingWithAStaleExpectedRevisionIsRejected(): void
    {
        // Une première correction, légitime, fait avancer la révision du lot.
        $this->client->request('POST', $this->path('correct'), server: $this->server, content: json_encode([
            'operationKey' => 'correct-key-cccc3333',
            'quantity' => 300,
            'unit' => 'g',
        ]));
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $afterFirst = $this->decode();
        self::assertSame(300.0, (float) $afterFirst['remainingQuantity']);

        // La seconde s'appuie sur la révision d'AVANT cette correction.
        $this->client->request('POST', $this->path('correct'), server: $this->server, content: json_encode([
            'operationKey' => 'correct-key-dddd4444',
            'quantity' => 250,
            'unit' => 'g',
            'expectedRevision' => $afterFirst['revision'] - 1,
        ]));

        self::assertSame(Response::HTTP_CONFLICT, $this->client->getResponse()->getStatusCode());
        self::assertSame(300.0, $this->remainingInDatabase(), 'Rien ne doit avoir été écrasé.');

        $conflict = $this->decode();
        self::assertArrayHasKey('current', $conflict);
        self::assertSame(300.0, (float) $conflict['current']['remainingQuantity']);
    }

    private function path(string $action): string
    {
        return sprintf('/api/stock-items/%s/%s', $this->lot->getId(), $action);
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertIsArray($payload);

        return $payload;
    }

    private function remainingInDatabase(): float
    {
        return (float) $this->secondConnection()->fetchOne(
            'SELECT remaining_quantity FROM stock_item WHERE id = ?',
            [(string) $this->lot->getId()],
        );
    }

    private function secondConnection(): Connection
    {
        return DriverManager::getConnection($this->entityManager->getConnection()->getParams());
    }

    /**
     * Lance « l'autre téléphone » dans un vrai processus : PHP est mono-thread,
     * et un verrou pris dans le même processus ne bloquerait jamais.
     *
     * @return resource
     */
    private function spawnConcurrentConsumer(string $lotId, float $newQuantity, int $holdMs)
    {
        $params = $this->entityManager->getConnection()->getParams();

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $params['host'] ?? '127.0.0.1',
            $params['port'] ?? 5432,
            $params['dbname'] ?? 'food_test',
        );

        $command = sprintf(
            'php %s %s %s %s %s %s %d',
            escapeshellarg(__DIR__.'/Fixtures/hold_and_consume.php'),
            escapeshellarg($dsn),
            escapeshellarg((string) ($params['user'] ?? 'food')),
            escapeshellarg((string) ($params['password'] ?? '')),
            escapeshellarg($lotId),
            escapeshellarg((string) $newQuantity),
            $holdMs,
        );

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!is_resource($process)) {
            self::markTestSkipped('Impossible de lancer un second processus PHP.');
        }

        $this->pipes = $pipes;

        return $process;
    }

    /** @var array<int, resource> */
    private array $pipes = [];

    /** @param resource $process */
    private function waitFor($process): void
    {
        $output = stream_get_contents($this->pipes[1]).stream_get_contents($this->pipes[2]);
        fclose($this->pipes[1]);
        fclose($this->pipes[2]);

        $status = proc_close($process);

        self::assertSame(0, $status, sprintf('Le processus concurrent a échoué : %s', trim($output)));
    }
}
