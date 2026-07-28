<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /api/health` traverse désormais la base et déclare la présence — jamais
 * la valeur — de la clé du générateur de recettes actif (leçon nº 10,
 * `tasks/lessons.md`). En environnement `test`, `GEMINI_API_KEY` est vide par
 * défaut et de façon déterministe : `.env.local` n'est jamais chargé quand
 * `APP_ENV=test`, quelles que soient les clés renseignées sur la machine du
 * développeur.
 */
class HealthControllerTest extends WebTestCase
{
    use RequiresDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;

        $this->skipUnlessDatabaseIsReachable($this->entityManager);
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            $this->entityManager->close();
        }

        parent::tearDown();
    }

    public function testReportsADegradedStatusWhenTheRecipeKeyIsMissing(): void
    {
        $this->client->request('GET', '/api/health');

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame('degraded', $payload['status']);
        self::assertTrue($payload['database']);
        self::assertSame('Google Gemini', $payload['recipeProvider']);
        self::assertFalse($payload['recipeProviderConfigured']);
    }

    /** La sonde ne doit révéler ni foyer, ni stock, ni valeur de secret. */
    public function testRevealsNothingBeyondItsOwnFields(): void
    {
        $this->client->request('GET', '/api/health');

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame(
            ['status', 'time', 'database', 'recipeProvider', 'recipeProviderConfigured'],
            array_keys($payload),
        );
    }

    /** Seule route publique : elle doit répondre sans X-Household ni X-Household-Key. */
    public function testDoesNotRequireAuthentication(): void
    {
        $this->client->request('GET', '/api/health');

        self::assertNotSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
        self::assertNotSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }
}
