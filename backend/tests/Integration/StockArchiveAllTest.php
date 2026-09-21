<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\Member;
use App\Entity\StockItem;
use App\Entity\StockMovement;
use App\Enum\MovementType;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Repository\StockMovementRepository;
use App\Service\HouseholdAccessKey;
use App\Service\Product\VariantIdentity;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * « Vider le stock » pour refaire l'inventaire après une absence.
 *
 * Testé par la porte HTTP et sur une vraie base (leçons nº 25 et nº 26) : ce qui
 * compte ici vit dans la rencontre du filtre SQL, du verrou et du garde
 * d'opération — aucun d'eux, pris seul, ne dit quels lots sont rangés.
 *
 * Le foyer compte quatre lots, un par cas : deux en stock (dont un qui a déjà
 * servi, donc un historique à préserver), un vide, un déjà archivé. Un second
 * foyer a le sien.
 */
class StockArchiveAllTest extends WebTestCase
{
    use RequiresDatabase;

    private const RESET_REASON = 'Inventaire remis à zéro';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Household $household;
    private Member $member;
    private StockItem $tomatoes;
    private StockItem $pasta;
    private StockItem $empty;
    private StockItem $alreadyArchived;
    private StockItem $neighbours;

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
        $this->member = new Member($this->household, 'Theo');
        $this->entityManager->persist($this->household);
        $this->entityManager->persist($this->member);

        $this->tomatoes = $this->lot($this->household, 'Tomates', 500);

        // 200 g déjà mangés : le taux de consommation dont vit le conseiller de
        // courses. C'est ce que la remise à zéro ne doit pas effacer.
        $this->pasta = $this->lot($this->household, 'Pâtes', 800);
        $this->entityManager->persist(new StockMovement(
            $this->pasta, MovementType::Consumption, -200, Unit::Gram, 800, 'Dîner', $this->member,
        ));

        $this->empty = $this->lot($this->household, 'Riz', 0);

        $this->alreadyArchived = $this->lot($this->household, 'Farine', 1000);
        $this->alreadyArchived->archive();

        $neighbourHousehold = new Household('Foyer voisin');
        $neighbourHousehold->setAccessKeyHash($keys->hash($keys->generate()));
        $this->entityManager->persist($neighbourHousehold);
        $this->neighbours = $this->lot($neighbourHousehold, 'Tomates', 300);

        $this->entityManager->flush();

        $this->server = [
            'HTTP_X_HOUSEHOLD' => (string) $this->household->getId(),
            'HTTP_X_HOUSEHOLD_KEY' => $accessKey,
            'HTTP_X_MEMBER' => (string) $this->member->getId(),
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

    public function testArchivesEveryLotInStockAndOnlyThose(): void
    {
        $archivedAtBefore = $this->column($this->alreadyArchived, 'archived_at');

        $response = $this->archiveAll('reset-key-aaaa1111');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(2, $response['archivedCount'], 'Le compte annoncé à l\'utilisateur doit être exact.');

        self::assertNotNull($this->column($this->tomatoes, 'archived_at'));
        self::assertNotNull($this->column($this->pasta, 'archived_at'));
        self::assertSame(500.0, (float) $this->column($this->tomatoes, 'remaining_quantity'), 'Ranger ne consomme rien.');
        self::assertSame(800.0, (float) $this->column($this->pasta, 'remaining_quantity'));

        self::assertNull($this->column($this->empty, 'archived_at'), 'Un lot vide n\'est plus de l\'inventaire : rien à ranger.');
        self::assertSame($archivedAtBefore, $this->column($this->alreadyArchived, 'archived_at'), 'Une archive garde sa date.');
        self::assertNull($this->column($this->neighbours, 'archived_at'), 'Le stock d\'un autre foyer n\'est jamais touché.');
    }

    /** Ce que l'utilisateur voit ensuite : un stock vide, et des archives complètes. */
    public function testTheInventoryIsEmptyAndTheArchivesHoldEverything(): void
    {
        $this->archiveAll('reset-key-bbbb2222');

        $this->client->request('GET', '/api/stock-items', server: $this->server);
        self::assertSame([], $this->decode()['items']);

        $this->client->request('GET', '/api/stock-items?archived=1', server: $this->server);
        $labels = array_map(
            static fn (array $item): string => $item['variant']['name'],
            $this->decode()['items'],
        );
        sort($labels);

        self::assertSame(['Farine', 'Pâtes', 'Tomates'], $labels);
    }

    /** Chaque lot rangé dit par qui, pourquoi, et au sein de quel geste. */
    public function testEachArchivedLotKeepsATraceOfTheReset(): void
    {
        $this->archiveAll('reset-key-cccc3333');

        $rows = $this->connection()->fetchAllAssociative(
            'SELECT m.type, m.quantity, m.remaining_after, m.created_by_id, o.type AS operation_type, o.status
               FROM stock_movement m
               JOIN stock_operation o ON o.id = m.operation_id
              WHERE m.reason = ?
              ORDER BY m.remaining_after',
            [self::RESET_REASON],
        );

        self::assertCount(2, $rows);

        foreach ($rows as $row) {
            self::assertSame(MovementType::Correction->value, $row['type']);
            self::assertSame(0.0, (float) $row['quantity'], 'Rien n\'est sorti du stock.');
            self::assertSame((string) $this->member->getId(), $row['created_by_id']);
            self::assertSame('inventory_reset', $row['operation_type']);
            self::assertSame('succeeded', $row['status']);
        }

        self::assertSame([500.0, 800.0], array_map(static fn (array $row) => (float) $row['remaining_after'], $rows));
    }

    /**
     * La garantie qui justifie d'archiver plutôt que de supprimer : une
     * consommation enregistrée avant la remise à zéro compte toujours après.
     * Une suppression physique l'aurait emportée en cascade.
     */
    public function testConsumptionHistorySurvivesTheReset(): void
    {
        $since = new \DateTimeImmutable('-1 day');
        self::assertCount(1, $this->movements()->findConsumptionSince($this->household, $since));

        $this->archiveAll('reset-key-dddd4444');

        $rows = $this->movements()->findConsumptionSince($this->household, $since);
        self::assertCount(1, $rows);
        self::assertSame(-200.0, (float) $rows[0]['quantity']);
    }

    /** Le geste reste défaisable lot par lot : on retrouve ce qu'on avait laissé. */
    public function testALotCanBeRestoredWithItsQuantity(): void
    {
        $this->archiveAll('reset-key-eeee5555');

        $this->client->request(
            'POST',
            sprintf('/api/stock-items/%s/restore', $this->tomatoes->getId()),
            server: $this->server,
            content: json_encode(['operationKey' => 'restore-key-ffff6666'], \JSON_THROW_ON_ERROR),
        );
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/stock-items', server: $this->server);
        $items = $this->decode()['items'];

        self::assertCount(1, $items);
        self::assertSame(500.0, (float) $items[0]['remainingQuantity']);
    }

    /**
     * Une réponse perdue, une nouvelle tentative : le serveur rend la réponse
     * d'origine au lieu de refaire le geste — sans quoi il rangerait aussi ce
     * que quelqu'un vient d'ajouter.
     */
    public function testReplayingTheSameKeyDoesNotArchiveALotAddedSince(): void
    {
        $this->archiveAll('reset-key-gggg7777');

        $fresh = $this->lot($this->household, 'Courgettes', 600);
        $this->entityManager->flush();

        $replay = $this->archiveAll('reset-key-gggg7777');

        self::assertTrue($replay['replayed'] ?? false);
        self::assertSame(2, $replay['archivedCount']);
        self::assertNull($this->column($fresh, 'archived_at'));
    }

    public function testTheHouseholdKeyIsRequired(): void
    {
        $server = $this->server;
        unset($server['HTTP_X_HOUSEHOLD_KEY']);

        $this->client->request('POST', '/api/stock-items/archive-all', server: $server, content: $this->body('reset-key-hhhh8888'));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->column($this->tomatoes, 'archived_at'));
    }

    /** Chaque mouvement porte son auteur : sans membre, pas de geste. */
    public function testAMemberIsRequired(): void
    {
        $server = $this->server;
        unset($server['HTTP_X_MEMBER']);

        $this->client->request('POST', '/api/stock-items/archive-all', server: $server, content: $this->body('reset-key-iiii9999'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->column($this->tomatoes, 'archived_at'));
    }

    /**
     * Pendant qu'on vide le stock, l'autre téléphone finit les tomates : il tient
     * le lot, le met à zéro, puis valide.
     *
     * Avec le verrou pris AVANT de lire, la remise à zéro attend, relit un lot
     * vide et le laisse — il n'est plus de l'inventaire. Sans lui, elle aurait
     * lu 500 g avant d'attendre, rangé le lot comme s'il était plein, et
     * annoncé un lot de trop à l'utilisateur.
     */
    public function testALotEmptiedWhileWaitingForItsLockIsLeftAlone(): void
    {
        $other = $this->spawnConcurrentConsumer((string) $this->tomatoes->getId(), 0.0, 700);

        // Le temps que l'autre processus prenne le verrou avant nous.
        usleep(250_000);

        $response = $this->archiveAll('reset-key-jjjj0000');

        $this->waitFor($other);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $response['archivedCount']);
        self::assertNull($this->column($this->tomatoes, 'archived_at'), 'Le lot vidé pendant l\'attente ne se range pas.');
        self::assertNotNull($this->column($this->pasta, 'archived_at'));
    }

    private function lot(Household $household, string $label, float $quantity): StockItem
    {
        $identity = VariantIdentity::of(mb_strtolower($label), Unit::Gram->family(), []);
        $variant = new HouseholdProductVariant($household, $label, $identity, $identity->slugBase());
        $lot = new StockItem($household, $variant, max($quantity, 1), Unit::Gram, StorageLocation::Pantry);
        $lot->setRemainingQuantity($quantity);

        $this->entityManager->persist($variant);
        $this->entityManager->persist($lot);

        return $lot;
    }

    /** @return array<string, mixed> */
    private function archiveAll(string $operationKey): array
    {
        $this->client->request('POST', '/api/stock-items/archive-all', server: $this->server, content: $this->body($operationKey));

        return $this->decode();
    }

    private function body(string $operationKey): string
    {
        return json_encode(['operationKey' => $operationKey], \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertIsArray($payload);

        return $payload;
    }

    /** Lu par une autre connexion : ce qui est écrit, pas ce que l'EntityManager croit. */
    private function column(StockItem $lot, string $column): mixed
    {
        return $this->connection()->fetchOne(
            sprintf('SELECT %s FROM stock_item WHERE id = ?', $column),
            [(string) $lot->getId()],
        );
    }

    private function connection(): Connection
    {
        return DriverManager::getConnection($this->entityManager->getConnection()->getParams());
    }

    private function movements(): StockMovementRepository
    {
        /** @var StockMovementRepository $repository */
        $repository = static::getContainer()->get(StockMovementRepository::class);

        return $repository;
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
