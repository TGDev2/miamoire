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
 * Le round-trip HTTP de `mutedReviewCategories`, en miroir de la couverture
 * déjà existante pour `dietaryExclusions` sur ce même contrôleur.
 */
class HouseholdMuteReviewCategoriesTest extends WebTestCase
{
    use RequiresDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private string $householdId;

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

        $this->householdId = (string) $household->getId();
        $this->authenticated = [
            'HTTP_X_HOUSEHOLD' => $this->householdId,
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

    public function testANewHouseholdMutesNoCategory(): void
    {
        $this->client->request('GET', "/api/households/{$this->householdId}", server: $this->authenticated);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame([], $payload['mutedReviewCategories']);
    }

    /** Doublons et entrées vides sont nettoyés, exactement comme `dietaryExclusions`. */
    public function testMutingACategoryRoundTripsCleaned(): void
    {
        $this->client->request(
            'PATCH',
            "/api/households/{$this->householdId}",
            server: $this->authenticated + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['mutedReviewCategories' => ['Épicerie', 'Épicerie', ' ', 'Surgelés']]),
        );

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['Épicerie', 'Surgelés'], $payload['mutedReviewCategories']);

        $this->client->request('GET', "/api/households/{$this->householdId}", server: $this->authenticated);
        $reloaded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['Épicerie', 'Surgelés'], $reloaded['mutedReviewCategories']);
    }
}
