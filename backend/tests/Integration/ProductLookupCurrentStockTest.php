<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\Member;
use App\Entity\Product;
use App\Entity\StockItem;
use App\Enum\ProductSource;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Service\HouseholdAccessKey;
use App\Service\Product\VariantIdentity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le mode « vérifier avant d'acheter » de l'Évolution 3 (`tasks/evolutions.md`) :
 * le scan d'un code-barres déjà connu doit immédiatement dire ce qui existe déjà
 * au foyer, sans passer par la fiche détaillée.
 */
class ProductLookupCurrentStockTest extends WebTestCase
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
        $member = new Member($this->household, 'Theo');

        $this->entityManager->persist($this->household);
        $this->entityManager->persist($member);
        $this->entityManager->flush();

        $this->authenticated = [
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

    public function testScanningAProductAlreadyInStockReturnsQuantityAndNextUseBy(): void
    {
        $product = $this->makeProduct('3000000000001', 'Riz');
        $variant = $this->makeVariant($product, 'Riz');
        $item = new StockItem($this->household, $variant, 500, Unit::Gram, StorageLocation::Pantry);
        $item->setExpirationDate(new \DateTimeImmutable('+5 days'));

        $this->entityManager->persist($product);
        $this->entityManager->persist($variant);
        $this->entityManager->persist($item);
        $this->entityManager->flush();

        $this->client->request('GET', '/api/products/barcode/3000000000001', server: $this->authenticated);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertNotNull($payload['currentStock']);
        self::assertEqualsWithDelta(500.0, $payload['currentStock']['quantity'], 0.01);
        self::assertSame('g', $payload['currentStock']['unit']);
        self::assertSame((new \DateTimeImmutable('+5 days'))->format('Y-m-d'), $payload['currentStock']['nextUseBy']);
    }

    public function testAKnownProductWithNothingInStockHasNoCurrentStock(): void
    {
        $product = $this->makeProduct('3000000000002', 'Pâtes');
        $variant = $this->makeVariant($product, 'Pâtes');

        $this->entityManager->persist($product);
        $this->entityManager->persist($variant);
        $this->entityManager->flush();

        $this->client->request('GET', '/api/products/barcode/3000000000002', server: $this->authenticated);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertNull($payload['currentStock']);
    }

    public function testAProductNeverAssociatedToAVariantHasNoCurrentStock(): void
    {
        $product = $this->makeProduct('3000000000003', 'Farine');
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        $this->client->request('GET', '/api/products/barcode/3000000000003', server: $this->authenticated);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertNull($payload['householdVariant']);
        self::assertNull($payload['currentStock']);
    }

    private function makeProduct(string $barcode, string $name): Product
    {
        $product = new Product($name, mb_strtolower($name), ProductSource::Manual);
        $product->setBarcode($barcode);

        return $product;
    }

    private function makeVariant(Product $product, string $name): HouseholdProductVariant
    {
        $identity = VariantIdentity::of(
            preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)) ?? $name,
            Unit::Gram->family(),
            [],
        );

        $variant = new HouseholdProductVariant($this->household, $name, $identity, $identity->slugBase());
        $variant->setProduct($product);

        return $variant;
    }
}
