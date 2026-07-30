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
 * Le contrat HTTP du plan de repas : l'invariant « exactement un des trois »
 * et le plafond d'entrées actives ne sont vérifiés qu'ici, dans le
 * contrôleur — un test de service ne les verrait pas.
 */
class MealPlanControllerTest extends WebTestCase
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

    private function createFreeformEntry(string $label = 'Resto ce soir'): void
    {
        $this->client->request(
            'POST',
            '/api/meal-plan/entries',
            server: $this->authenticated,
            content: json_encode(['date' => '2026-08-05', 'mealSlot' => 'dinner', 'label' => $label]),
        );
    }

    public function testCreatingAFreeformEntrySucceeds(): void
    {
        $this->createFreeformEntry();

        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('freeform', $payload['kind']);
        self::assertSame('Resto ce soir', $payload['displayName']);
    }

    public function testCreatingAnEntryWithNoContentIsRejected(): void
    {
        $this->client->request(
            'POST',
            '/api/meal-plan/entries',
            server: $this->authenticated,
            content: json_encode(['date' => '2026-08-05', 'mealSlot' => 'dinner']),
        );

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
    }

    public function testCreatingAnEntryWithTwoKindsOfContentAtOnceIsRejected(): void
    {
        $this->client->request(
            'POST',
            '/api/meal-plan/entries',
            server: $this->authenticated,
            content: json_encode([
                'date' => '2026-08-05',
                'mealSlot' => 'dinner',
                'label' => 'Resto ce soir',
                'recipe' => ['recipeName' => 'Test'],
            ]),
        );

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
    }

    /** Le plan reste court : un 5ᵉ repas actif est refusé plutôt que d'ouvrir la porte à un calendrier complet. */
    public function testAFifthActiveEntryIsRejected(): void
    {
        for ($i = 0; $i < 4; ++$i) {
            $this->createFreeformEntry(sprintf('Repas %d', $i));
            self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        }

        $this->createFreeformEntry('Un de trop');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
    }

    public function testReschedulingAndDeletingAnEntry(): void
    {
        $this->createFreeformEntry();
        $created = json_decode((string) $this->client->getResponse()->getContent(), true);
        $id = $created['id'];

        $this->client->request(
            'PATCH',
            "/api/meal-plan/entries/{$id}",
            server: $this->authenticated,
            content: json_encode(['date' => '2026-08-06', 'mealSlot' => 'lunch']),
        );
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $rescheduled = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('2026-08-06', $rescheduled['date']);
        self::assertSame('lunch', $rescheduled['mealSlot']);

        $this->client->request('DELETE', "/api/meal-plan/entries/{$id}", server: $this->authenticated);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/meal-plan', server: $this->authenticated);
        $overview = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertCount(0, $overview['entries']);
    }
}
