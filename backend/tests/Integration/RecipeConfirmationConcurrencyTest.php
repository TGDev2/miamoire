<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\Member;
use App\Entity\StockItem;
use App\Enum\StockOperationStatus;
use App\Enum\Unit;
use App\Exception\StockConflictException;
use App\Exception\StockException;
use App\Repository\StockOperationRepository;
use App\Service\Ai\RecipeConfirmationService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Ce que seule une vraie base peut démontrer.
 *
 * Quatre défauts sont vérifiés ici, et aucun n'est observable avec des doublures :
 * l'unicité de la clé d'opération est portée par un index, le verrou l'est par
 * PostgreSQL, et l'atomicité par une transaction. Une doublure dirait toujours oui.
 */
class RecipeConfirmationConcurrencyTest extends DatabaseTestCase
{
    private RecipeConfirmationService $service;
    private StockOperationRepository $operations;
    private Household $household;
    private Member $member;
    private HouseholdProductVariant $variant;
    private StockItem $lot;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var RecipeConfirmationService $service */
        $service = static::getContainer()->get(RecipeConfirmationService::class);
        $this->service = $service;

        /** @var StockOperationRepository $operations */
        $operations = static::getContainer()->get(StockOperationRepository::class);
        $this->operations = $operations;

        $this->household = $this->makeHousehold();
        $this->member = $this->makeMember($this->household);
        $this->variant = $this->makeVariant($this->household, 'Tomates', Unit::Gram);
        $this->lot = $this->makeLot($this->household, $this->variant, 500, Unit::Gram, '+3 days');

        $this->entityManager->flush();
    }

    /** @return list<array<string, mixed>> */
    private function proposal(float $quantity): array
    {
        return [[
            'key' => $this->variant->getSlug(),
            'label' => 'Tomates',
            'quantity' => $quantity,
            'unit' => 'g',
        ]];
    }

    /**
     * Le défaut d'origine : une réponse réseau perdue, une nouvelle tentative,
     * et le stock était déduit deux fois.
     */
    public function testReplayingTheSameKeyDeductsOnlyOnce(): void
    {
        $first = $this->service->confirm(
            $this->household,
            $this->member,
            $this->proposal(200),
            'Salade de tomates',
            'operation-key-aaaa1111',
        );

        $second = $this->service->confirm(
            $this->household,
            $this->member,
            $this->proposal(200),
            'Salade de tomates',
            'operation-key-aaaa1111',
        );

        self::assertFalse($first['replayed']);
        self::assertTrue($second['replayed'], 'Le serveur doit reconnaître le geste, pas le refaire.');
        self::assertSame(300.0, $this->remainingInDatabase($this->lot), 'Une seule déduction en base.');

        // La réponse rejouée est celle de la première exécution, à l'identique.
        self::assertSame($first['lines'], $second['lines']);
    }

    /** Une même clé ne peut pas servir à deux gestes différents. */
    public function testReusingAKeyForAnotherRequestIsRefused(): void
    {
        $this->service->confirm(
            $this->household,
            $this->member,
            $this->proposal(200),
            'Salade',
            'operation-key-bbbb2222',
        );

        $this->expectException(StockException::class);
        $this->expectExceptionMessage('déjà servi');

        $this->service->confirm(
            $this->household,
            $this->member,
            $this->proposal(50),
            'Autre recette',
            'operation-key-bbbb2222',
        );
    }

    /**
     * L'unicité n'est pas une convention applicative : elle est refusée par la base,
     * y compris si deux processus contournaient la lecture préalable.
     */
    public function testTheDatabaseItselfRefusesADuplicateOperationKey(): void
    {
        $this->service->confirm(
            $this->household,
            $this->member,
            $this->proposal(100),
            'Salade',
            'operation-key-cccc3333',
        );

        $connection = $this->secondConnection();

        $this->expectException(UniqueConstraintViolationException::class);

        $connection->executeStatement(
            'INSERT INTO stock_operation (id, household_id, operation_key, type, request_hash, status, created_at)
             VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, NOW())',
            [
                (string) $this->household->getId(),
                'operation-key-cccc3333',
                'recipe_confirmation',
                str_repeat('0', 64),
                'pending',
            ],
        );
    }

    /**
     * Tout ou rien : une recette à moitié déduite laisse un stock faux et un
     * utilisateur qui ne sait plus ce qu'il lui reste.
     */
    public function testAnIncompletePlanWritesNothingAtAll(): void
    {
        $secondVariant = $this->makeVariant($this->household, 'Riz', Unit::Gram);
        $this->makeLot($this->household, $secondVariant, 100, Unit::Gram);
        $this->entityManager->flush();

        try {
            $this->service->confirm(
                $this->household,
                $this->member,
                [
                    ['key' => $this->variant->getSlug(), 'label' => 'Tomates', 'quantity' => 200, 'unit' => 'g'],
                    ['key' => $secondVariant->getSlug(), 'label' => 'Riz', 'quantity' => 900, 'unit' => 'g'],
                ],
                'Recette impossible',
                'operation-key-dddd4444',
            );
            self::fail('Un plan incomplet doit être refusé.');
        } catch (StockConflictException $conflict) {
            self::assertArrayHasKey('proposal', $conflict->getContext());
            self::assertSame('riz', $conflict->getContext()['proposal'][0]['key']);
            self::assertSame(100.0, $conflict->getContext()['proposal'][0]['available']);
        }

        // La ligne réalisable ne doit pas avoir été déduite pour autant.
        self::assertSame(500.0, $this->remainingInDatabase($this->lot));

        $operation = $this->operations->findOneByKey($this->household, 'operation-key-dddd4444');
        self::assertNotNull($operation);
        self::assertSame(StockOperationStatus::Failed, $operation->getStatus());
    }

    /** Une clé libérée par un échec doit rester utilisable. */
    public function testAFailedOperationCanBeRetriedWithTheSameKey(): void
    {
        try {
            $this->service->confirm(
                $this->household,
                $this->member,
                $this->proposal(5000),
                'Trop',
                'operation-key-eeee5555',
            );
        } catch (StockConflictException) {
            // attendu
        }

        $result = $this->service->confirm(
            $this->household,
            $this->member,
            $this->proposal(200),
            'Trop',
            'operation-key-eeee5555',
        );

        self::assertFalse($result['replayed']);
        self::assertSame(300.0, $this->remainingInDatabase($this->lot));
    }

    /**
     * Deux lignes du même ingrédient réservaient chacune le même lot : le total
     * annoncé dépassait le stock réel.
     */
    public function testDuplicateLinesAreDeductedOnce(): void
    {
        $result = $this->service->confirm(
            $this->household,
            $this->member,
            [
                ['key' => $this->variant->getSlug(), 'label' => 'Tomates', 'quantity' => 200, 'unit' => 'g'],
                ['key' => $this->variant->getSlug(), 'label' => 'Tomates', 'quantity' => 100, 'unit' => 'g'],
            ],
            'Deux usages',
            'operation-key-ffff6666',
        );

        self::assertCount(1, $result['lines']);
        self::assertSame(300.0, $result['lines'][0]['applied']);
        self::assertSame(200.0, $this->remainingInDatabase($this->lot));
    }

    /**
     * La mise à jour perdue — le défaut que le verrou existe pour empêcher.
     *
     * Un autre processus verrouille le lot, le tient, puis descend la quantité de
     * 500 à 50 g et valide. Pendant ce temps, une confirmation de 200 g arrive.
     *
     * - **Avec** le verrou pris avant la planification : la confirmation attend,
     *   relit 50 g, constate que son plan n'est plus réalisable et n'écrit RIEN.
     *   Le stock final vaut 50 g.
     * - **Sans** le verrou : elle aurait planifié sur les 500 g lus avant
     *   l'attente, puis écrit 500 − 200 = 300 g par-dessus les 50 g de l'autre.
     *   Deux cent cinquante grammes de tomates seraient apparus de nulle part.
     *
     * C'est le stock final qui distingue les deux cas — et c'est pour cela que ce
     * test ne peut pas se contenter d'observer une exception : une écriture qui
     * bloque puis passe ne lève, elle, aucune erreur.
     */
    public function testALotConsumedElsewhereDuringTheWaitIsNotOverwritten(): void
    {
        $lotId = (string) $this->lot->getId();
        $other = $this->spawnConcurrentConsumer($lotId, 50.0, 800);

        // Le temps que l'autre processus prenne le verrou avant nous.
        usleep(300_000);

        $refused = false;

        try {
            $this->service->confirm(
                $this->household,
                $this->member,
                $this->proposal(200),
                'Concurrente',
                'operation-key-gggg7777',
            );
        } catch (StockConflictException) {
            $refused = true;
        }

        $this->waitFor($other);

        self::assertSame(
            50.0,
            $this->remainingInDatabase($this->lot),
            'La quantité écrite par l\'autre processus doit survivre : pas de mise à jour perdue.',
        );
        self::assertTrue(
            $refused,
            'Le plan doit être recalculé après l\'attente, et refusé puisque 200 g ne sont plus disponibles.',
        );
    }

    /**
     * Lance « l'autre téléphone » dans un vrai processus.
     *
     * PHP est mono-thread : un verrou pris dans le même processus ne bloquerait
     * jamais, et le test passerait sans rien démontrer.
     *
     * @return resource
     */
    private function spawnConcurrentConsumer(string $lotId, float $quantity, int $holdMs)
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
            escapeshellarg((string) $quantity),
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

        self::assertSame(
            0,
            $status,
            sprintf('Le processus concurrent a échoué : %s', trim($output)),
        );
    }
}
