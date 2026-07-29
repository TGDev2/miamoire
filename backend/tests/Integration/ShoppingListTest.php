<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Household;
use App\Entity\Member;
use App\Entity\Product;
use App\Enum\ProductSource;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Repository\HouseholdRepository;
use App\Repository\MemberRepository;
use App\Repository\ProductRepository;
use App\Service\HouseholdAccessKey;
use App\Service\Product\VariantResolver;
use App\Service\Stock\StockService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * La liste de courses du foyer : ajout groupé et dédoublonné, bascule
 * cochée/non cochée, retrait, et rapprochement au scan via
 * `GET /barcode/{barcode}`.
 */
class ShoppingListTest extends WebTestCase
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

    public function testAddingTheSameIngredientTwiceDoesNotDuplicateTheLine(): void
    {
        $this->postItems([
            ['label' => 'Courgette', 'quantity' => 300, 'unit' => 'g'],
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());

        $this->postItems([
            ['label' => 'Courgette', 'quantity' => 500, 'unit' => 'g'],
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/shopping-list', server: $this->authenticated);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertCount(1, $payload['items']);
        self::assertSame('Courgette', $payload['items'][0]['label']);
        // Le second ajout est un no-op : la quantité du premier survit.
        self::assertEqualsWithDelta(300.0, $payload['items'][0]['quantity'], 0.001);
    }

    public function testBareReminderWithoutQuantityIsAccepted(): void
    {
        $this->postItems([
            ['label' => 'Pain', 'unit' => 'piece'],
        ]);

        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNull($payload['items'][0]['quantity']);
    }

    public function testTogglingAndRemovingAnItem(): void
    {
        $this->postItems([['label' => 'Farine', 'quantity' => 1, 'unit' => 'kg']]);
        $created = json_decode((string) $this->client->getResponse()->getContent(), true);
        $id = $created['items'][0]['id'];

        $this->client->request(
            'PATCH',
            "/api/shopping-list/{$id}",
            server: $this->authenticated,
            content: json_encode(['checked' => true]),
        );
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $updated = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($updated['checked']);
        self::assertNotNull($updated['checkedAt']);

        $this->client->request('DELETE', "/api/shopping-list/{$id}", server: $this->authenticated);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/shopping-list', server: $this->authenticated);
        $afterDelete = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertCount(0, $afterDelete['items']);
    }

    /**
     * Le cœur du rapprochement : un ingrédient ajouté à la liste — sans
     * qu'aucun achat n'ait jamais eu lieu — doit être retrouvé quand le
     * produit correspondant est scanné.
     */
    public function testShoppingListItemAppearsOnBarcodeLookupBeforeAnyPurchase(): void
    {
        $product = new Product('Pâte à tartiner', 'ingredient-key', ProductSource::OpenFoodFacts);
        $product->setBarcode('3017620422006');
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        // Personne n'a encore rien acheté : pas de variante, pas de rapprochement possible.
        $this->client->request('GET', '/api/products/barcode/3017620422006', server: $this->authenticated);
        $beforeList = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNull($beforeList['shoppingListItem']);

        // La recette veut de la « Pâte à tartiner » — même nom, même unité que le
        // catalogue : la résolution retombe sur la même variante que le produit.
        $this->postItems([['label' => 'Pâte à tartiner', 'quantity' => 400, 'unit' => 'g']]);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());

        /** @var VariantResolver $resolver */
        $resolver = static::getContainer()->get(VariantResolver::class);
        $variant = $resolver->resolve($this->household, $this->reloadProduct('3017620422006'), 'Pâte à tartiner', Unit::Gram);
        self::assertNotNull($variant->getId(), 'Le produit et la ligne de liste doivent résoudre à la même variante.');

        $this->client->request('GET', '/api/products/barcode/3017620422006', server: $this->authenticated);
        $afterList = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertNotNull($afterList['shoppingListItem'], 'La ligne de la liste doit être retrouvée au scan.');
        self::assertSame('Pâte à tartiner', $afterList['shoppingListItem']['label']);
        self::assertFalse($afterList['shoppingListItem']['checked']);
    }

    /**
     * Le rapprochement doit rester indulgent : une ligne cochée en magasin
     * reste retrouvable, on ne veut pas exiger que l'utilisateur ait pensé
     * à cocher avant de scanner.
     */
    public function testCheckedShoppingListItemStillReconciles(): void
    {
        $product = new Product('Yaourt nature', 'ingredient-key', ProductSource::OpenFoodFacts);
        $product->setBarcode('3017620422007');
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        $this->postItems([['label' => 'Yaourt nature', 'quantity' => 500, 'unit' => 'g']]);
        $created = json_decode((string) $this->client->getResponse()->getContent(), true);
        $id = $created['items'][0]['id'];

        $this->client->request(
            'PATCH',
            "/api/shopping-list/{$id}",
            server: $this->authenticated,
            content: json_encode(['checked' => true]),
        );

        /** @var StockService $stockService */
        $stockService = static::getContainer()->get(StockService::class);
        /** @var VariantResolver $resolver */
        $resolver = static::getContainer()->get(VariantResolver::class);
        $household = $this->reloadHousehold();
        $variant = $resolver->resolve($household, $this->reloadProduct('3017620422007'), 'Yaourt nature', Unit::Gram);
        $stockService->add(
            household: $household,
            author: $this->reloadMember(),
            variant: $variant,
            numberOfPackages: 1,
            quantityPerPackage: 500,
            unit: Unit::Gram,
            location: StorageLocation::Fridge,
        );

        $this->client->request('GET', '/api/products/barcode/3017620422007', server: $this->authenticated);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertNotNull($payload['shoppingListItem']);
        self::assertTrue($payload['shoppingListItem']['checked']);
    }

    /** @param list<array{label: string, quantity?: int|float, unit: string}> $items */
    private function postItems(array $items): void
    {
        $this->client->request(
            'POST',
            '/api/shopping-list/items',
            server: $this->authenticated,
            content: json_encode(['items' => $items]),
        );
    }

    /**
     * Le client HTTP réinitialise l'entity manager entre deux requêtes : un
     * objet chargé avant un appel `client->request()` devient détaché. On le
     * recharge donc plutôt que de réutiliser la référence PHP d'origine.
     */
    private function reloadProduct(string $barcode): Product
    {
        /** @var ProductRepository $products */
        $products = static::getContainer()->get(ProductRepository::class);
        $product = $products->findOneByBarcode($barcode);

        self::assertNotNull($product);

        return $product;
    }

    private function reloadHousehold(): Household
    {
        /** @var HouseholdRepository $households */
        $households = static::getContainer()->get(HouseholdRepository::class);
        $household = $households->find($this->household->getId());

        self::assertNotNull($household);

        return $household;
    }

    private function reloadMember(): Member
    {
        /** @var MemberRepository $members */
        $members = static::getContainer()->get(MemberRepository::class);
        $member = $members->find($this->member->getId());

        self::assertNotNull($member);

        return $member;
    }
}
