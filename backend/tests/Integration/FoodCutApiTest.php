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
 * Le contrat HTTP des trois demandes de cette itération, vu par l'application.
 *
 * L'écran mobile ne code aucun libellé en dur : il n'affiche que ce que ces
 * réponses contiennent. Une découpe absente du contrat n'est donc pas une
 * régression discrète, c'est une rubrique qui disparaît.
 */
class FoodCutApiTest extends WebTestCase
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
        ];
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            $this->entityManager->close();
        }

        parent::tearDown();
    }

    public function testTheReferenceShipsTheNewEquipmentAndTheCuts(): void
    {
        $this->client->request('GET', '/api/reference', server: $this->authenticated);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        $equipment = array_column($payload['equipment'], 'label', 'value');
        self::assertSame('Barbecue', $equipment['barbecue']);
        self::assertSame('Plancha', $equipment['plancha']);
        self::assertSame('Robot cuiseur', $equipment['multi_cooker']);
        // Les six d'avant restent, au même identifiant : une puce cochée hier
        // doit rester cochable aujourd'hui.
        self::assertArrayHasKey('oven', $equipment);
        self::assertArrayHasKey('airfryer', $equipment);

        $cuts = array_column($payload['productCuts'], 'label', 'value');
        self::assertSame('Escalopes', $cuts['escalope']);
        self::assertSame('Émincé', $cuts['strips']);

        // Absorbé par la découpe : le laisser afficherait une puce « Coupé /
        // en morceaux » à côté d'une rubrique « Découpe », et les deux se
        // contrediraient sur le même aliment.
        self::assertNotContains('sliced', array_column($payload['productTraits'], 'value'));
    }

    /** La question n'est posée que lorsqu'elle change une recette. */
    public function testTheProfileOnlyOffersCutsWhereTheyMatter(): void
    {
        $this->client->request('GET', '/api/products/profile?name=Blanc+de+poulet', server: $this->authenticated);
        $poultry = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertContains('escalope', $poultry['plausibleCuts']);
        self::assertContains('slices', $poultry['plausibleCuts']);
        self::assertNull($poultry['detectedCut'], 'Rien dans ce libellé ne nomme une forme.');

        $this->client->request('GET', '/api/products/profile?name=Farine+de+ble', server: $this->authenticated);
        $flour = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame([], $flour['plausibleCuts'], 'Une farine n\'a pas de découpe.');
    }

    /** « Escalopes de poulet » nomme déjà sa forme : la puce arrive cochée. */
    public function testTheProfilePreselectsAFormTheLabelAlreadyNames(): void
    {
        $this->client->request('GET', '/api/products/profile?name=Escalopes+de+poulet', server: $this->authenticated);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame('escalope', $payload['detectedCut']);
        self::assertSame(150, $payload['approxPieceWeight'], 'Une escalope pèse environ 150 g.');
    }

    /**
     * Le défaut d'origine, vu par l'application : deux ajouts au libellé différent
     * doivent produire deux aliments distincts, et non un seul que le générateur
     * pourrait prendre pour l'autre.
     */
    public function testTwoCutsCreateTwoDistinctFoods(): void
    {
        $escalopes = $this->addStockItem('Escalopes de poulet');
        $slices = $this->addStockItem('Blanc de poulet en tranches');

        self::assertSame('escalope', $escalopes['variant']['cut']);
        self::assertSame('slices', $slices['variant']['cut']);
        self::assertNotSame($escalopes['variant']['key'], $slices['variant']['key']);

        // Le nom énonce déjà la forme : le libellé ne la répète pas entre
        // parenthèses. « Blanc de poulet en tranches (en tranches) » ne disait
        // rien de plus et se lisait mal, sur l'écran comme dans le prompt.
        self::assertSame('Escalopes de poulet', $escalopes['variant']['culinaryLabel']);
        self::assertSame('Blanc de poulet en tranches', $slices['variant']['culinaryLabel']);
    }

    /** La correction d'un appui depuis la fiche recette, vue côté serveur. */
    public function testCorrectingTheCutRewritesTheKeyTheGeneratorCopies(): void
    {
        $created = $this->addStockItem('Blanc de poulet');
        self::assertNull($created['variant']['cut']);

        $this->client->request(
            'PATCH',
            sprintf('/api/stock-items/%s', $created['id']),
            server: $this->authenticated + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['cut' => 'strips', 'operationKey' => 'test-correction-1'], \JSON_THROW_ON_ERROR),
        );

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame('strips', $payload['variant']['cut']);
        self::assertSame('user', $payload['variant']['cutOrigin'], 'Une correction ne se fait plus redéduire.');
        // La clé recopiée par le générateur, pas seulement un libellé d'affichage.
        self::assertSame('blanc-poulet-emince', $payload['variant']['key']);
        self::assertSame('Blanc de poulet (émincé)', $payload['variant']['culinaryLabel']);
    }

    /** @return array<string, mixed> */
    private function addStockItem(string $name): array
    {
        static $operation = 0;

        $this->client->request(
            'POST',
            '/api/stock-items',
            server: $this->authenticated + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'name' => $name,
                'unit' => 'piece',
                'quantityPerPackage' => 2,
                'operationKey' => sprintf('test-ajout-%08d', ++$operation),
            ], \JSON_THROW_ON_ERROR),
        );

        self::assertSame(
            Response::HTTP_CREATED,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $payload['items'][0];
    }
}
