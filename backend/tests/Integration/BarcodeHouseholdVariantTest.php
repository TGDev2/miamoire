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
use App\Service\Stock\StockService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `GET /api/products/barcode/{barcode}` reconnaît un produit déjà acheté par
 * CE foyer — c'est ce qui préremplit le « Retour de courses » depuis les
 * habitudes d'achat plutôt que depuis les seuls défauts du catalogue.
 */
class BarcodeHouseholdVariantTest extends WebTestCase
{
    use RequiresDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Household $household;
    private Member $member;
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
        $this->member = new Member($this->household, 'Theo');

        $this->entityManager->persist($this->household);
        $this->entityManager->persist($this->member);
        $this->entityManager->flush();

        $this->authenticated = [
            'HTTP_X_HOUSEHOLD' => (string) $this->household->getId(),
            'HTTP_X_HOUSEHOLD_KEY' => $accessKey,
            'HTTP_X_MEMBER' => (string) $this->member->getId(),
        ];
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            $this->entityManager->close();
        }

        parent::tearDown();
    }

    public function testNoHouseholdVariantWhenNeverPurchased(): void
    {
        $this->makeProduct('3017620422003');

        $this->client->request('GET', '/api/products/barcode/3017620422003', server: $this->authenticated);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($payload['found']);
        self::assertNull($payload['householdVariant']);
    }

    public function testHouseholdVariantAppearsAfterAPurchaseWithItsHabits(): void
    {
        $product = $this->makeProduct('3017620422004', 'Pâte à tartiner');
        $this->purchase($product, 'Pâte à tartiner', 400, Unit::Gram, StorageLocation::Pantry);

        $this->client->request('GET', '/api/products/barcode/3017620422004', server: $this->authenticated);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNotNull($payload['householdVariant']);
        self::assertSame('Pâte à tartiner', $payload['householdVariant']['name']);
        self::assertEqualsWithDelta(400.0, $payload['householdVariant']['usualPackageSize'], 0.001);
        self::assertSame('g', $payload['householdVariant']['usualPackageUnit']);
        self::assertSame('pantry', $payload['householdVariant']['usualStorageLocation']);
    }

    /**
     * Le cas qui justifie le choix du lien direct au produit plutôt que du
     * recalcul de l'empreinte : renommer localement change l'empreinte
     * d'identité, mais ne doit pas faire perdre la reconnaissance au scan.
     */
    public function testHouseholdVariantSurvivesALocalRename(): void
    {
        $product = $this->makeProduct('3017620422005', 'Yaourt nature');
        $variant = $this->purchase($product, 'Yaourt nature', 125, Unit::Gram, StorageLocation::Fridge);

        /** @var VariantResolver $resolver */
        $resolver = static::getContainer()->get(VariantResolver::class);
        $resolver->rename($variant, 'Yaourt maison');
        $this->entityManager->flush();

        $this->client->request('GET', '/api/products/barcode/3017620422005', server: $this->authenticated);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNotNull($payload['householdVariant'], 'Le renommage ne doit pas casser la reconnaissance au scan.');
        self::assertSame('Yaourt maison', $payload['householdVariant']['name']);
    }

    private function makeProduct(string $barcode, string $name = 'Produit'): Product
    {
        $product = new Product($name, 'ingredient-key', ProductSource::OpenFoodFacts);
        $product->setBarcode($barcode);
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        return $product;
    }

    private function purchase(
        Product $product,
        string $name,
        float $quantity,
        Unit $unit,
        StorageLocation $location,
    ): \App\Entity\HouseholdProductVariant {
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

        return $variant;
    }
}
