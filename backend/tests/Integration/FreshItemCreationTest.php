<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Household;
use App\Entity\Member;
use App\Enum\FoodNature;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Repository\HouseholdProductVariantRepository;
use App\Repository\StockItemRepository;
use App\Service\HouseholdAccessKey;
use App\Service\Stock\StockService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ce que l'ajout d'une courgette doit produire.
 *
 * Trois courgettes, c'est un seul chiffre à saisir. Le serveur en tire un lot de
 * trois exemplaires d'une pièce, une date estimée d'office — parce qu'aucune
 * courgette ne porte de date imprimée — et un aliment qui ne sera jamais décrit
 * comme « entamé ».
 */
class FreshItemCreationTest extends WebTestCase
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
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    /**
     * Aucune date n'est envoyée, aucune consigne d'estimation non plus : c'est
     * exactement ce que produit un formulaire où la question ne se pose pas. Le
     * serveur doit estimer quand même — sans quoi la courgette entrerait sans
     * date, invisible pour les alertes et « Aujourd'hui », et pourrirait
     * silencieusement dans le bac à légumes.
     */
    public function testAFreshItemGetsAnEstimatedDateWithoutBeingAsked(): void
    {
        $item = $this->createStockItem([
            'name' => 'Courgettes',
            'numberOfPackages' => 3,
            'quantityPerPackage' => 1,
            'unit' => 'piece',
            'storageLocation' => 'fridge',
        ]);

        self::assertSame('fresh', $item['variant']['nature']);
        self::assertNotNull($item['expirationDate'], 'Une courgette doit toujours repartir avec une date.');
        self::assertTrue($item['expirationIsEstimated'], 'Et cette date doit être annoncée comme estimée.');

        // Sept jours au réfrigérateur, d'après le lexique — pas une date inventée.
        self::assertSame(
            (new \DateTimeImmutable('today +7 days'))->format('Y-m-d'),
            $item['expirationDate'],
        );
    }

    /** Un produit conditionné sans date en reste dépourvu : rien ne change pour lui. */
    public function testAPackagedItemIsNotDatedBehindTheUsersBack(): void
    {
        $item = $this->createStockItem([
            'name' => 'Tomates pelées',
            'traits' => ['canned'],
            'quantityPerPackage' => 400,
            'unit' => 'g',
            'storageLocation' => 'pantry',
        ]);

        self::assertSame('packaged', $item['variant']['nature']);
        self::assertNull($item['expirationDate']);
    }

    /** Une date lue sur l'emballage prime toujours, frais ou pas. */
    public function testAnExplicitDateIsNeverOverwritten(): void
    {
        $item = $this->createStockItem([
            'name' => 'Courgettes',
            'quantityPerPackage' => 2,
            'unit' => 'piece',
            'storageLocation' => 'fridge',
            'batches' => [['numberOfPackages' => 1, 'expirationDate' => '2026-09-15']],
        ]);

        self::assertSame('2026-09-15', $item['expirationDate']);
        self::assertFalse($item['expirationIsEstimated']);
    }

    /** Refuser explicitement l'estimation reste possible : c'est quelqu'un qui tranche. */
    public function testAnExplicitRefusalToEstimateIsRespected(): void
    {
        $item = $this->createStockItem([
            'name' => 'Courgettes',
            'quantityPerPackage' => 2,
            'unit' => 'piece',
            'estimateExpiration' => false,
            'storageLocation' => 'fridge',
        ]);

        self::assertNull($item['expirationDate']);
    }

    /**
     * Le mappage qui fait disparaître la notion d'exemplaire sans y toucher :
     * une courgette est un exemplaire d'une pièce. En retirer une en retire
     * exactement une, et le lot n'est jamais « entamé » — parce qu'on n'entame
     * pas une courgette.
     */
    public function testACountedFreshLotIsNeverOpened(): void
    {
        $created = $this->createStockItem([
            'name' => 'Courgettes',
            'numberOfPackages' => 3,
            'quantityPerPackage' => 1,
            'unit' => 'piece',
            'storageLocation' => 'fridge',
        ]);

        // `assertEqualsWithDelta` et non `assertSame` : JSON sérialise 3.0 en 3.
        self::assertEqualsWithDelta(3.0, $created['remainingQuantity'], 0.001);
        self::assertCount(3, $created['packages']);
        self::assertFalse($created['isOpened']);

        /** @var StockItemRepository $stockItems */
        $stockItems = static::getContainer()->get(StockItemRepository::class);
        /** @var StockService $stock */
        $stock = static::getContainer()->get(StockService::class);

        $item = $stockItems->find($created['id']);
        self::assertNotNull($item);

        $stock->consumeOnePackage($item, $item->getAddedBy() ?? new Member($this->household, 'Theo'));

        self::assertSame(2.0, $item->getRemainingQuantity(), 'Retirer un exemplaire retire UNE courgette.');
        self::assertFalse($item->isOpened(), 'Une courgette ne s\'entame pas.');
    }

    /** Corriger la nature est un choix qui doit survivre à toute redéduction. */
    public function testAnExplicitNatureIsRemembered(): void
    {
        $item = $this->createStockItem([
            'name' => 'Kombucha maison',
            'nature' => 'fresh',
            'quantityPerPackage' => 500,
            'unit' => 'ml',
            'storageLocation' => 'fridge',
        ]);

        self::assertSame('fresh', $item['variant']['nature']);
        self::assertSame('user', $item['variant']['natureOrigin']);

        /** @var HouseholdProductVariantRepository $variants */
        $variants = static::getContainer()->get(HouseholdProductVariantRepository::class);
        $variant = $variants->findOneBySlug($this->household, $item['variant']['key']);

        self::assertNotNull($variant);
        self::assertSame(FoodNature::Fresh, $variant->getNature());
    }

    /** Un aliment frais que le lexique connaît propose son unité naturelle. */
    public function testTheProfileRouteDescribesWhatTheFormNeeds(): void
    {
        $this->client->request(
            'GET',
            '/api/products/profile?name=Courgettes&storageLocation=fridge',
            server: $this->server,
        );

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame('fresh', $payload['nature']);
        self::assertSame(Unit::Piece->value, $payload['naturalUnit']);
        self::assertSame(250, $payload['approxPieceWeight']);
        self::assertSame(7, $payload['shelfLife']['days']);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> le premier lot créé
     */
    private function createStockItem(array $body): array
    {
        $this->client->request(
            'POST',
            '/api/stock-items',
            server: $this->server,
            content: json_encode($body + [
                'operationKey' => bin2hex(random_bytes(8)),
                'storageLocation' => StorageLocation::Pantry->value,
            ], \JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());

        $payload = json_decode((string) $response->getContent(), true);

        return $payload['items'][0];
    }
}
