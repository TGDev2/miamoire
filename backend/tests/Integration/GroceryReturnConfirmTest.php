<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Household;
use App\Entity\Member;
use App\Entity\Product;
use App\Enum\ProductSource;
use App\Enum\StockOperationStatus;
use App\Enum\StockOperationType;
use App\Enum\Unit;
use App\Repository\ShoppingListItemRepository;
use App\Repository\StockItemRepository;
use App\Repository\StockMovementRepository;
use App\Repository\StockOperationRepository;
use App\Service\HouseholdAccessKey;
use App\Service\Shopping\ShoppingListService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * A whole return written as one operation: every lot or none, replayable,
 * and refused before anything is written when a line is at fault.
 */
class GroceryReturnConfirmTest extends WebTestCase
{
    use RequiresDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Household $household;
    private Member $member;

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

    public function testAWholeReturnIsWrittenAsOneOperation(): void
    {
        $this->makeProduct('3017620422003', 'Pâte à tartiner');

        /** @var ShoppingListService $list */
        $list = static::getContainer()->get(ShoppingListService::class);
        [$listItem] = $list->addMany($this->household, $this->member, [
            ['label' => 'Pâtes', 'quantity' => 500, 'unit' => Unit::Gram],
        ]);
        $listItemId = (string) $listItem->getId();

        $payload = $this->confirm('retour-mardi-01', [
            [
                'lineKey' => 'a',
                'barcode' => '3017620422003',
                'name' => 'Pâte à tartiner',
                'unit' => 'g',
                'quantityPerPackage' => 400,
                'numberOfPackages' => 1,
                'storageLocation' => 'pantry',
                'estimateExpiration' => true,
            ],
            [
                'lineKey' => 'b',
                'name' => 'Courgettes',
                'unit' => 'piece',
                'quantityPerPackage' => 1,
                'numberOfPackages' => 3,
                'storageLocation' => 'fridge',
                'batches' => [
                    ['numberOfPackages' => 2, 'expirationDate' => '2026-11-01', 'estimateExpiration' => false],
                    ['numberOfPackages' => 1, 'estimateExpiration' => true],
                ],
            ],
            [
                'lineKey' => 'c',
                'shoppingListItemId' => $listItemId,
                'name' => 'Pâtes',
                'unit' => 'g',
                'quantityPerPackage' => 500,
                'numberOfPackages' => 1,
                'storageLocation' => 'pantry',
                'estimateExpiration' => false,
            ],
        ], Response::HTTP_CREATED);

        self::assertCount(4, $payload['items'], 'One lot for the spread, two for the courgettes, one for the pasta.');
        self::assertSame([$listItemId], $payload['removedShoppingListItemIds']);
        self::assertArrayNotHasKey('replayed', $payload);

        $byKey = [];
        foreach ($payload['items'] as $item) {
            $byKey[$item['variant']['name']][] = $item;
        }

        self::assertTrue($byKey['Pâte à tartiner'][0]['expirationIsEstimated'], 'Asked to estimate: estimated.');
        self::assertSame('2026-11-01', $byKey['Courgettes'][0]['expirationDate']);
        self::assertFalse($byKey['Courgettes'][0]['expirationIsEstimated']);
        self::assertTrue($byKey['Courgettes'][1]['expirationIsEstimated']);
        self::assertNull($byKey['Pâtes'][0]['expirationDate'], 'Cleared on purpose: stays cleared.');
        // The habit is learnt from the return: next time the spread comes pre-filled.
        self::assertEqualsWithDelta(400.0, $byKey['Pâte à tartiner'][0]['variant']['usualPackageSize'], 0.001);

        /** @var ShoppingListItemRepository $listItems */
        $listItems = static::getContainer()->get(ShoppingListItemRepository::class);
        self::assertSame([], $listItems->findForHousehold($this->household), 'The matched list line is gone.');

        /** @var StockOperationRepository $operations */
        $operations = static::getContainer()->get(StockOperationRepository::class);
        $operation = $operations->findOneByKey($this->household, 'retour-mardi-01');
        self::assertNotNull($operation);
        self::assertSame(StockOperationType::GroceryReturn, $operation->getType());
        self::assertSame(StockOperationStatus::Succeeded, $operation->getStatus());

        /** @var StockItemRepository $stockItems */
        $stockItems = static::getContainer()->get(StockItemRepository::class);
        /** @var StockMovementRepository $movements */
        $movements = static::getContainer()->get(StockMovementRepository::class);
        foreach ($stockItems->findAvailable($this->household) as $item) {
            $addition = $movements->findForStockItem($item)[0];
            self::assertSame('Retour de courses', $addition->getReason());
            self::assertNotNull($addition->getOperation());
            self::assertTrue($addition->getOperation()->getId()->equals($operation->getId()));
        }
    }

    /** A lost answer followed by a second tap must not put the groceries away twice. */
    public function testReplayingTheSameKeyWritesNothingMore(): void
    {
        $lines = [[
            'lineKey' => 'a',
            'name' => 'Courgettes',
            'unit' => 'piece',
            'quantityPerPackage' => 1,
            'numberOfPackages' => 2,
            'storageLocation' => 'fridge',
        ]];

        $first = $this->confirm('retour-rejoue-01', $lines, Response::HTTP_CREATED);
        $second = $this->confirm('retour-rejoue-01', $lines, Response::HTTP_CREATED);

        self::assertTrue($second['replayed']);
        self::assertSame($first['items'][0]['id'], $second['items'][0]['id']);

        /** @var StockItemRepository $stockItems */
        $stockItems = static::getContainer()->get(StockItemRepository::class);
        self::assertCount(1, $stockItems->findAvailable($this->household));
    }

    /** Every line at fault is named, nothing is written, and the key stays usable. */
    public function testALineAtFaultRefusesTheWholeReturnBeforeAnyWrite(): void
    {
        $valid = [
            'lineKey' => 'ok',
            'name' => 'Courgettes',
            'unit' => 'piece',
            'quantityPerPackage' => 1,
            'numberOfPackages' => 2,
            'storageLocation' => 'fridge',
        ];
        $noQuantity = [
            'lineKey' => 'bad-quantity',
            'name' => 'Riz',
            'unit' => 'g',
            'quantityPerPackage' => 0,
            'numberOfPackages' => 1,
            'storageLocation' => 'pantry',
        ];
        $noName = [
            'lineKey' => 'bad-name',
            'name' => '',
            'unit' => 'g',
            'quantityPerPackage' => 500,
            'numberOfPackages' => 1,
            'storageLocation' => 'pantry',
        ];

        $rejected = $this->confirm('retour-refuse-01', [$valid, $noQuantity, $noName], Response::HTTP_UNPROCESSABLE_ENTITY);

        self::assertSame(['bad-quantity', 'bad-name'], array_column($rejected['lines'], 'lineKey'));
        self::assertNotSame('', $rejected['lines'][0]['message']);

        /** @var StockItemRepository $stockItems */
        $stockItems = static::getContainer()->get(StockItemRepository::class);
        self::assertSame([], $stockItems->findAvailable($this->household), 'The valid line was not written either.');

        // Corrected and sent again with the SAME key: the key was never reserved.
        $noQuantity['quantityPerPackage'] = 1000;
        $noName['name'] = 'Farine';
        $accepted = $this->confirm('retour-refuse-01', [$valid, $noQuantity, $noName], Response::HTTP_CREATED);

        self::assertCount(3, $accepted['items']);
        self::assertArrayNotHasKey('replayed', $accepted);
    }

    public function testAnEmptyReturnIsRefused(): void
    {
        $this->confirm('retour-vide-01', [], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return array<string, mixed>
     */
    private function confirm(string $operationKey, array $lines, int $expectedStatus): array
    {
        $this->client->request(
            'POST',
            '/api/grocery-returns/confirm',
            server: $this->server,
            content: json_encode(['operationKey' => $operationKey, 'lines' => $lines], \JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();
        self::assertSame($expectedStatus, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function makeProduct(string $barcode, string $name): Product
    {
        $product = new Product($name, 'ingredient-key', ProductSource::OpenFoodFacts);
        $product->setBarcode($barcode);
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        return $product;
    }
}
