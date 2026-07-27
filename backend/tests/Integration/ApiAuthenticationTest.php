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
 * Le contrat d'authentification, vérifié route par route.
 *
 * Il n'était jusqu'ici garanti que par une habitude : un contrôleur qui pense à
 * demander `RequestContext`. `RequestContextTest` prouve que le service refuse
 * bien un code absent ou faux, mais rien ne prouvait qu'une route l'appelle.
 * `ProductController` ne l'appelait pas — trois routes ouvertes, dont une
 * écriture dans le catalogue partagé par tous les foyers.
 *
 * Ce test attaque donc l'API par HTTP, sans en-tête, et vérifie qu'elle refuse.
 * C'est la seule forme qui aurait attrapé l'oubli : aucun test de service ne
 * peut voir une dépendance qu'on n'a pas déclarée.
 */
class ApiAuthenticationTest extends WebTestCase
{
    use RequiresDatabase;

    /**
     * Les routes délibérément publiques, et elles seules.
     *
     * Toute route absente de cette liste doit exiger `X-Household` et
     * `X-Household-Key`. La liste est courte parce qu'elle décrit ce qui précède
     * l'accès à un foyer : vérifier que le serveur répond, en créer un, le rejoindre.
     */
    private const PUBLIC_ROUTES = [
        ['GET', '/api/health'],
        ['POST', '/api/households'],
        ['POST', '/api/households/join'],
    ];

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private string $householdId;
    private string $memberId;
    private string $accessKey;

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

        $this->accessKey = $keys->generate();
        $household = new Household('Foyer de test');
        $household->setAccessKeyHash($keys->hash($this->accessKey));
        $member = new Member($household, 'Theo');

        $this->entityManager->persist($household);
        $this->entityManager->persist($member);
        $this->entityManager->flush();

        $this->householdId = (string) $household->getId();
        $this->memberId = (string) $member->getId();
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            $this->entityManager->close();
        }

        parent::tearDown();
    }

    /**
     * Le défaut corrigé, dans sa forme la plus grave : sans aucun en-tête, on
     * pouvait écrire dans le catalogue partagé mondialement par tous les foyers.
     *
     * Le refus est ici un 400 et non un 401, et c'est le contrat existant : sans
     * `X-Household`, la requête ne désigne aucun foyer dont on pourrait vérifier
     * le code. Le 401 arrive dès qu'un foyer est nommé — les cas suivants. Ce qui
     * compte pour la sûreté est identique dans les deux cas : refusé, rien écrit.
     */
    public function testContributingToTheSharedCatalogueIsRefusedWithoutAHousehold(): void
    {
        $this->client->request(
            'POST',
            '/api/products',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['barcode' => '3017620422003', 'name' => 'Intrus']),
        );

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        self::assertSame(
            0,
            (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM product'),
            'Un appel refusé n\'écrit rien.',
        );
    }

    /**
     * Deux lectures qui semblaient inoffensives. L'une déclenche un appel sortant
     * vers Open Food Facts, dont le quota est commun à tous les foyers.
     */
    public function testProductLookupsAreRefusedWithoutAHousehold(): void
    {
        foreach (['/api/products/barcode/3017620422003', '/api/products/shelf-life?name=tomate'] as $path) {
            $this->client->request('GET', $path);

            self::assertFalse(
                $this->client->getResponse()->isSuccessful(),
                sprintf('%s ne doit rien répondre sans foyer.', $path),
            );
        }
    }

    /**
     * L'identifiant du foyer circule dans toutes les réponses de l'API : il n'est
     * pas un secret et n'autorise donc rien par lui-même. C'est le code qui
     * authentifie — et c'est ici que le 401 doit tomber.
     */
    public function testTheHouseholdIdentifierAloneIsNotEnough(): void
    {
        foreach (['/api/products/barcode/3017620422003', '/api/products/shelf-life?name=tomate'] as $path) {
            $this->client->request('GET', $path, server: ['HTTP_X_HOUSEHOLD' => $this->householdId]);

            self::assertSame(
                Response::HTTP_UNAUTHORIZED,
                $this->client->getResponse()->getStatusCode(),
                sprintf('%s doit exiger le code du foyer.', $path),
            );
        }
    }

    public function testContributingWithTheIdentifierButNoKeyIsRefused(): void
    {
        $this->client->request(
            'POST',
            '/api/products',
            server: ['HTTP_X_HOUSEHOLD' => $this->householdId, 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['barcode' => '3017620422003', 'name' => 'Intrus']),
        );

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
        self::assertSame(
            0,
            (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM product'),
        );
    }

    public function testAWrongKeyIsRefused(): void
    {
        $this->client->request('GET', '/api/products/shelf-life?name=tomate', server: [
            'HTTP_X_HOUSEHOLD' => $this->householdId,
            'HTTP_X_HOUSEHOLD_KEY' => 'AAAA-AAAA-AAAA-AAAA-AAAA',
        ]);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Le pendant indispensable : un contrôle qui écarte les appels légitimes finit
     * par être désactivé, donc il ne protège plus de rien (leçon nº 14).
     */
    public function testTheEstimationStillAnswersWithAValidKey(): void
    {
        $this->client->request('GET', '/api/products/shelf-life?name=tomate', server: $this->authenticated());

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('days', $payload);
    }

    /** Une écriture exige en plus de dire qui agit — la règle vaut ici aussi. */
    public function testContributingWithoutSayingWhoIsRefused(): void
    {
        $server = $this->authenticated();
        unset($server['HTTP_X_MEMBER']);
        $server['CONTENT_TYPE'] = 'application/json';

        $this->client->request(
            'POST',
            '/api/products',
            server: $server,
            content: json_encode(['barcode' => '3017620422003', 'name' => 'Pâte à tartiner']),
        );

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }

    public function testContributingWorksOnceAuthenticated(): void
    {
        $this->client->request(
            'POST',
            '/api/products',
            server: $this->authenticated() + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['barcode' => '3017620422003', 'name' => 'Pâte à tartiner']),
        );

        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Le stock lui-même, pour que le test dise « toute l'API » et pas « les
     * produits » : c'est l'invariant qu'on veut tenir, pas le correctif du jour.
     */
    public function testStockRoutesRequireAKeyToo(): void
    {
        $this->client->request('GET', '/api/stock-items', server: [
            'HTTP_X_HOUSEHOLD' => $this->householdId,
        ]);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/stock-items', server: $this->authenticated());

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    /** Et à l'inverse : ce qui doit rester ouvert le reste. */
    public function testTheRoutesThatPrecedeAHouseholdStayOpen(): void
    {
        foreach (self::PUBLIC_ROUTES as [$method, $path]) {
            $this->client->request(
                $method,
                $path,
                server: ['CONTENT_TYPE' => 'application/json'],
                content: 'POST' === $method ? '{}' : null,
            );

            self::assertNotSame(
                Response::HTTP_UNAUTHORIZED,
                $this->client->getResponse()->getStatusCode(),
                sprintf('%s %s précède l\'accès à un foyer : il ne peut pas en exiger le code.', $method, $path),
            );
        }
    }

    /** @return array<string, string> */
    private function authenticated(): array
    {
        return [
            'HTTP_X_HOUSEHOLD' => $this->householdId,
            'HTTP_X_HOUSEHOLD_KEY' => $this->accessKey,
            'HTTP_X_MEMBER' => $this->memberId,
        ];
    }
}
