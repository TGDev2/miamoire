<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Household;
use App\Entity\Member;
use App\Entity\Product;
use App\Enum\ProductSource;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Service\HouseholdAccessKey;
use App\Service\Product\VariantResolver;
use App\Service\Shopping\ShoppingListService;
use App\Service\Stock\StockService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the triage screen is told about each line of a return, and from where.
 *
 * The rule under test: a value the person gave is kept; an empty one is
 * filled from the household's own habits first, the catalogue next, the
 * lexicons last — and every filled value says where it comes from.
 */
class GroceryReturnResolveTest extends WebTestCase
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

    /** A product bought before comes back with the household's own name, package and place. */
    public function testAScannedProductBoughtBeforeIsFilledFromItsHabits(): void
    {
        $product = $this->makeProduct('3017620422003', 'Pâte à tartiner noisettes 400g');
        $this->purchase($product, 'Pâte à tartiner', 400, Unit::Gram, StorageLocation::Pantry);

        $line = $this->resolveOne(['lineKey' => 'a', 'barcode' => '3017620422003']);

        self::assertSame('ready', $line['status']);
        self::assertSame('none', $line['decision']);
        self::assertSame('Pâte à tartiner', $line['name']);
        self::assertNotNull($line['variant']);
        self::assertSame('g', $line['unit']);
        self::assertEqualsWithDelta(400.0, $line['quantityPerPackage'], 0.001);
        self::assertSame('pantry', $line['storageLocation']);
        self::assertSame('habit', $line['provenance']['package']);
        self::assertSame('habit', $line['provenance']['storage']);
        self::assertSame('estimated', $line['provenance']['date']);
        self::assertTrue($line['expirationIsEstimated']);
        self::assertNotNull($line['expirationDate']);
        self::assertNotNull($line['expirationExplanation']);
    }

    /** Never bought: the catalogue's package, and the lexicon's place for this food. */
    public function testAScannedProductNeverBoughtIsFilledFromTheCatalogue(): void
    {
        $product = $this->makeProduct('3017620422010', 'Riz basmati');
        $product->setPackageQuantity(1000);
        $product->setPackageUnit(Unit::Gram);
        $this->entityManager->flush();

        $line = $this->resolveOne(['lineKey' => 'b', 'barcode' => '3017620422010']);

        self::assertSame('ready', $line['status']);
        self::assertSame('Riz basmati', $line['name']);
        self::assertNull($line['variant']);
        self::assertEqualsWithDelta(1000.0, $line['quantityPerPackage'], 0.001);
        self::assertSame('catalog', $line['provenance']['package']);
        self::assertSame('pantry', $line['storageLocation']);
        self::assertSame('default', $line['provenance']['storage']);
    }

    /** Milk is chilled whatever its carton says: the lexicon decides, and the estimate is shown, not asked. */
    public function testAChilledPackageGetsItsEstimateWithoutAQuestion(): void
    {
        $this->makeProduct('3017620422011', 'Lait demi-écrémé');

        $line = $this->resolveOne(['lineKey' => 'c', 'barcode' => '3017620422011']);

        self::assertSame('fridge', $line['storageLocation']);
        self::assertSame('ready', $line['status']);
        self::assertSame('none', $line['decision']);
        self::assertTrue($line['expirationIsEstimated']);
        self::assertNotNull($line['expirationExplanation']);
    }

    /** Eggs and cheese are proteins, but nobody freezes them on the way home: no question. */
    public function testEggsAndCheeseAreNotAskedAboutTheFreezer(): void
    {
        $eggs = $this->resolveOne(['lineKey' => 'e2', 'name' => 'Œufs plein air', 'unit' => 'piece', 'quantityPerPackage' => 1, 'numberOfPackages' => 6]);
        self::assertSame('fridge', $eggs['storageLocation']);
        self::assertSame('none', $eggs['decision']);

        $cheese = $this->resolveOne(['lineKey' => 'e3', 'name' => 'Emmental râpé']);
        self::assertSame('fridge', $cheese['storageLocation'], 'The lexicon learnt the cheeses a receipt names without "fromage".');
        self::assertSame('none', $cheese['decision']);
    }

    /** "SURG" on the printed label keeps frozen peas in the freezer even when the readable name lost it. */
    public function testThePrintedLabelKeepsTheStateTheReadableNameDropped(): void
    {
        $line = $this->resolveOne(['lineKey' => 'e4', 'name' => 'Petits pois carottes', 'receiptLabel' => 'PETITS POIS CAR.SURGELES 1KG']);

        self::assertContains('frozen', $line['traits']);
        self::assertSame('freezer', $line['storageLocation']);
        self::assertSame('none', $line['decision']);
    }

    /** A line ticked on the list resolves straight to the food the list already named. */
    public function testAShoppingListLineResolvesToItsFood(): void
    {
        /** @var ShoppingListService $list */
        $list = static::getContainer()->get(ShoppingListService::class);
        [$item] = $list->addMany($this->household, $this->member, [
            ['label' => 'Courgettes', 'quantity' => null, 'unit' => Unit::Piece],
        ]);

        $line = $this->resolveOne(['lineKey' => 'd', 'shoppingListItemId' => (string) $item->getId()]);

        self::assertSame('ready', $line['status']);
        self::assertSame('Courgettes', $line['name']);
        self::assertNotNull($line['variant']);
        self::assertNotNull($line['shoppingListItem']);
        self::assertSame('fresh', $line['nature']);
        self::assertSame('piece', $line['unit']);
        // A counted fresh food is one piece per package: nobody is asked a weight per courgette.
        self::assertEqualsWithDelta(1.0, $line['quantityPerPackage'], 0.001);
        self::assertSame('fridge', $line['storageLocation']);
        self::assertSame((new \DateTimeImmutable('today +7 days'))->format('Y-m-d'), $line['expirationDate']);
        self::assertTrue($line['expirationIsEstimated']);
    }

    /** Meat with no habit: the freezer stops the clock, so the line asks. */
    public function testMeatWithoutAHabitAsksAboutTheFreezer(): void
    {
        $line = $this->resolveOne(['lineKey' => 'e', 'name' => 'Poulet fermier']);

        self::assertSame('fridge', $line['storageLocation']);
        self::assertSame('default', $line['provenance']['storage']);
        self::assertSame('fridge_or_freezer', $line['decision']);
        self::assertSame('needs_decision', $line['status']);
    }

    /** The same meat bought before goes where it went last time, and asks nothing. */
    public function testMeatWithAHabitGoesWhereItWentLastTime(): void
    {
        $product = $this->makeProduct('3017620422012', 'Poulet fermier');
        $this->purchase($product, 'Poulet fermier', 1200, Unit::Gram, StorageLocation::Freezer);

        $line = $this->resolveOne(['lineKey' => 'f', 'barcode' => '3017620422012']);

        self::assertSame('freezer', $line['storageLocation']);
        self::assertSame('habit', $line['provenance']['storage']);
        self::assertSame('none', $line['decision']);
    }

    public function testAnUnknownBarcodeWithoutANameCannotBeWritten(): void
    {
        $line = $this->resolveOne(['lineKey' => 'g', 'barcode' => '4000000000001']);

        self::assertSame('unknown', $line['status']);
        self::assertSame('unknown_food', $line['decision']);
        self::assertSame('', $line['name']);
    }

    /** The household counts flour by weight: pieces are a question, not a silent new line. */
    public function testAUnitFromAnotherFamilyThanTheHouseholdsIsAQuestion(): void
    {
        $product = $this->makeProduct('3017620422013', 'Farine de blé');
        $this->purchase($product, 'Farine', 1000, Unit::Gram, StorageLocation::Pantry);

        $line = $this->resolveOne(['lineKey' => 'h', 'barcode' => '3017620422013', 'unit' => 'piece']);

        self::assertSame('unit_mismatch', $line['decision']);
        self::assertSame('mass', $line['unitFamily']);
        self::assertSame('piece', $line['unit'], 'The given unit is echoed, never corrected behind the back.');
    }

    /** Whatever the person typed is kept, and said to be theirs. */
    public function testGivenValuesAreNeverOverridden(): void
    {
        $line = $this->resolveOne([
            'lineKey' => 'i',
            'name' => 'Courgettes',
            'unit' => 'piece',
            'quantityPerPackage' => 3,
            'numberOfPackages' => 2,
            'storageLocation' => 'pantry',
        ]);

        self::assertEqualsWithDelta(3.0, $line['quantityPerPackage'], 0.001);
        self::assertSame(2, $line['numberOfPackages']);
        self::assertSame('pantry', $line['storageLocation']);
        self::assertSame('given', $line['provenance']['package']);
        self::assertSame('given', $line['provenance']['storage']);
        // Estimated where it will actually be kept: four days in the pantry, not seven in the fridge.
        self::assertSame((new \DateTimeImmutable('today +4 days'))->format('Y-m-d'), $line['expirationDate']);
    }

    public function testAPrintedDateWinsAndAClearedDateStaysCleared(): void
    {
        $printed = $this->resolveOne(['lineKey' => 'j', 'name' => 'Courgettes', 'expirationDate' => '2026-12-24']);
        self::assertSame('2026-12-24', $printed['expirationDate']);
        self::assertFalse($printed['expirationIsEstimated']);
        self::assertSame('printed', $printed['provenance']['date']);

        $cleared = $this->resolveOne(['lineKey' => 'k', 'name' => 'Courgettes', 'estimateExpiration' => false]);
        self::assertNull($cleared['expirationDate']);
        self::assertSame('none', $cleared['provenance']['date']);
    }

    /** @return array<string, mixed> */
    private function resolveOne(array $line): array
    {
        $this->client->request(
            'POST',
            '/api/grocery-returns/resolve',
            server: $this->server,
            content: json_encode(['lines' => [$line]], \JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $payload = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertCount(1, $payload['lines']);
        self::assertSame($line['lineKey'], $payload['lines'][0]['lineKey']);

        return $payload['lines'][0];
    }

    private function makeProduct(string $barcode, string $name): Product
    {
        $product = new Product($name, 'ingredient-key', ProductSource::OpenFoodFacts);
        $product->setBarcode($barcode);
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        return $product;
    }

    private function purchase(Product $product, string $name, float $quantity, Unit $unit, StorageLocation $location): void
    {
        /** @var VariantResolver $resolver */
        $resolver = static::getContainer()->get(VariantResolver::class);
        /** @var StockService $stockService */
        $stockService = static::getContainer()->get(StockService::class);

        $variant = $resolver->resolve($this->household, $product, $name, $unit);

        $stockService->add(
            household: $this->household,
            author: $this->member,
            variant: $variant,
            numberOfPackages: 1,
            quantityPerPackage: $quantity,
            unit: $unit,
            location: $location,
        );
    }
}
