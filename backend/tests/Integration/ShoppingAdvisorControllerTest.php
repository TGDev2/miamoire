<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\Member;
use App\Entity\StockItem;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Service\HouseholdAccessKey;
use App\Service\Product\VariantIdentity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le contrat HTTP du conseiller avant les courses : la suggestion expose bien
 * raisonnement et confiance (exigés par `tasks/evolutions.md`), la désactivation
 * est isolée par foyer et persiste après rechargement.
 */
class ShoppingAdvisorControllerTest extends WebTestCase
{
    use RequiresDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Household $household;
    private HouseholdProductVariant $riz;
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

        $this->riz = $this->makeVariant($this->household, 'Riz');
        // Périme demain : garantit une suggestion « à consommer bientôt » pour
        // vérifier le contrat JSON sans dépendre du rythme de consommation.
        $item = new StockItem($this->household, $this->riz, 500, Unit::Gram, StorageLocation::Pantry);
        $item->setExpirationDate(new \DateTimeImmutable('+1 day'));

        $this->entityManager->persist($this->household);
        $this->entityManager->persist($member);
        $this->entityManager->persist($this->riz);
        $this->entityManager->persist($item);
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

    public function testListExposesReasoningAndConfidenceForEachSuggestion(): void
    {
        $this->client->request('GET', '/api/shopping-advisor', server: $this->authenticated);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertCount(1, $payload['items']);
        self::assertSame('use_soon_dont_add', $payload['items'][0]['kind']);
        self::assertSame('certaine', $payload['items'][0]['confidence']);
        self::assertNotSame('', $payload['items'][0]['reasoning']);
        self::assertSame((string) $this->riz->getId(), $payload['items'][0]['variant']['id']);
    }

    public function testMutingRemovesTheVariantFromTheList(): void
    {
        $this->client->request('POST', sprintf('/api/shopping-advisor/%s/mute', $this->riz->getId()), server: $this->authenticated);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/shopping-advisor', server: $this->authenticated);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame([], $payload['items']);
    }

    public function testMutingIsPersistedAndListedForReactivation(): void
    {
        $this->client->request('POST', sprintf('/api/shopping-advisor/%s/mute', $this->riz->getId()), server: $this->authenticated);

        $this->client->request('GET', '/api/shopping-advisor/muted', server: $this->authenticated);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertCount(1, $payload['items']);
        self::assertSame((string) $this->riz->getId(), $payload['items'][0]['id']);
    }

    public function testUnmutingBringsTheVariantBack(): void
    {
        $this->client->request('POST', sprintf('/api/shopping-advisor/%s/mute', $this->riz->getId()), server: $this->authenticated);
        $this->client->request('POST', sprintf('/api/shopping-advisor/%s/unmute', $this->riz->getId()), server: $this->authenticated);

        $this->client->request('GET', '/api/shopping-advisor', server: $this->authenticated);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertCount(1, $payload['items']);
    }

    public function testMutingAVariantFromAnotherHouseholdIsRejected(): void
    {
        $otherHousehold = new Household('Autre foyer');
        $otherHousehold->setAccessKeyHash(hash('sha256', 'autre-clef'));
        $otherVariant = $this->makeVariant($otherHousehold, 'Pâtes');

        $this->entityManager->persist($otherHousehold);
        $this->entityManager->persist($otherVariant);
        $this->entityManager->flush();

        $this->client->request('POST', sprintf('/api/shopping-advisor/%s/mute', $otherVariant->getId()), server: $this->authenticated);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    private function makeVariant(Household $household, string $name): HouseholdProductVariant
    {
        $identity = VariantIdentity::of(
            preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)) ?? $name,
            Unit::Gram->family(),
            [],
        );

        return new HouseholdProductVariant($household, $name, $identity, $identity->slugBase());
    }
}
