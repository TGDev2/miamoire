<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Household;
use App\Entity\Member;
use App\Service\Ai\Generator\RecipeGeneratorFactory;
use App\Service\Ai\Generator\RecipeGeneratorInterface;
use App\Service\HouseholdAccessKey;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * A receipt photographed at the checkout becomes lines of the basket, and
 * what the household confirms about a printed label is remembered.
 *
 * The model is faked: what is under test is everything around it — the
 * route, the reading, the resolver, the aliases — never the transcription.
 */
class GroceryReturnReceiptTest extends WebTestCase
{
    use RequiresDatabase;

    /** A 1×1 PNG: enough for the route to accept a photo. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Household $household;
    private Member $member;

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
        $this->member = new Member($this->household, 'Theo');

        $this->entityManager->persist($this->household);
        $this->entityManager->persist($this->member);
        $this->entityManager->flush();

        $this->server = [
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

    public function testAReceiptBecomesLinesOfTheBasket(): void
    {
        $this->fakeReceipt($this->leclercReceipt());

        $reading = $this->readReceipt();

        self::assertSame('E.LECLERC', $reading['store']);
        self::assertSame('2026-10-20', $reading['receiptDate']);
        self::assertEqualsWithDelta(42.5, $reading['total'], 0.001);
        self::assertCount(2, $reading['lines'], 'Two foods; the batteries are set aside.');

        [$milk, $courgettes] = $reading['lines'];

        self::assertSame('Lait demi-écrémé', $milk['name'], 'The model\'s readable name, since nothing was taught yet.');
        self::assertSame('l', $milk['unit']);
        self::assertEqualsWithDelta(1.0, $milk['quantityPerPackage'], 0.001);
        self::assertSame('given', $milk['provenance']['package'], 'The receipt gave the package: it is a fact.');
        self::assertSame('fridge', $milk['storageLocation'], 'The lexicon knows where milk goes.');
        self::assertNull($milk['variant']);
        self::assertSame('LAIT 1/2 ECR 6X1L', $milk['receipt']['rawLabel']);
        self::assertSame('E.LECLERC', $milk['receipt']['store']);
        self::assertEqualsWithDelta(5.4, $milk['receipt']['lineTotal'], 0.001);

        self::assertSame('Courgettes', $courgettes['name']);
        self::assertSame('kg', $courgettes['unit']);
        self::assertEqualsWithDelta(0.842, $courgettes['quantityPerPackage'], 0.001);
        self::assertSame(2, $courgettes['numberOfPackages']);

        self::assertCount(1, $reading['ignored']);
        self::assertSame('PILES AA X4', $reading['ignored'][0]['rawLabel']);
        self::assertFalse($reading['ignored'][0]['learned']);
    }

    /** What the household confirms about a label is what the next receipt starts from. */
    public function testAConfirmedLineTeachesItsLabelAndAnIgnoredOneIsSkipped(): void
    {
        $this->fakeReceipt($this->leclercReceipt());
        $first = $this->readReceipt();
        $milk = $first['lines'][0];

        $this->freshClient();
        $this->client->request(
            'POST',
            '/api/grocery-returns/confirm',
            server: $this->server + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'operationKey' => 'retour-ticket-01',
                'lines' => [[
                    'lineKey' => 'a',
                    'name' => 'Lait demi-écrémé',
                    'unit' => 'l',
                    'quantityPerPackage' => 1,
                    'numberOfPackages' => 6,
                    'storageLocation' => 'fridge',
                    'estimateExpiration' => true,
                    'receipt' => $milk['receipt'],
                ]],
                'ignored' => [['store' => 'E.LECLERC', 'rawLabel' => 'PILES AA X4']],
            ], \JSON_THROW_ON_ERROR),
        );
        $confirmed = $this->decode(Response::HTTP_CREATED);
        self::assertSame(2, $confirmed['learntLabels']);

        // The same labels again, read differently by the model this time:
        // the household's word must win over the model's.
        $secondReceipt = $this->leclercReceipt();
        $secondReceipt['lines'][0]['expandedName'] = 'Lait';
        $secondReceipt['lines'][2]['isFood'] = true;
        $this->fakeReceipt($secondReceipt);

        $second = $this->readReceipt();

        self::assertCount(2, $second['lines']);
        $milkAgain = $second['lines'][0];
        self::assertNotNull($milkAgain['variant'], 'The label now names the household\'s food outright.');
        self::assertSame('Lait demi-écrémé', $milkAgain['name']);
        self::assertSame('habit', $milkAgain['provenance']['storage']);
        self::assertSame('fridge', $milkAgain['storageLocation']);
        // A habit answers where it goes, not what the carton says: a chilled
        // package with a five-day estimate still asks for its printed date.
        self::assertSame('short_date', $milkAgain['decision']);

        self::assertCount(1, $second['ignored']);
        self::assertSame('PILES AA X4', $second['ignored'][0]['rawLabel']);
        self::assertTrue($second['ignored'][0]['learned'], 'Set aside once, set aside again — by the household, not the model.');
    }

    /** The app sends the photo in base64 inside a JSON body: same reading, same checks. */
    public function testAReceiptCanBeSentAsBase64Json(): void
    {
        $this->fakeReceipt($this->leclercReceipt());

        $this->client->request(
            'POST',
            '/api/grocery-returns/receipt',
            server: $this->server + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['images' => [['mediaType' => 'image/png', 'data' => self::PNG]]], \JSON_THROW_ON_ERROR),
        );
        $reading = $this->decode(Response::HTTP_OK);

        self::assertCount(2, $reading['lines']);
        self::assertSame('Lait demi-écrémé', $reading['lines'][0]['name']);

        // Not an image, whatever the declared type says.
        $this->freshClient();
        $this->client->request(
            'POST',
            '/api/grocery-returns/receipt',
            server: $this->server + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['images' => [['mediaType' => 'image/png', 'data' => base64_encode('LAIT 5,40')]]], \JSON_THROW_ON_ERROR),
        );
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }

    public function testAnUnreadableAnswerIsRefusedWithoutAGuess(): void
    {
        $generator = $this->createMock(RecipeGeneratorInterface::class);
        $generator->method('generate')->willReturn('Je ne vois pas de ticket sur cette photo.');
        $this->freshClient($generator);

        $this->postReceipt([$this->photo()]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
    }

    public function testAReceiptNeedsAPhoto(): void
    {
        $this->postReceipt([]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());

        $path = tempnam(sys_get_temp_dir(), 'ticket');
        file_put_contents($path, "LAIT 1/2 ECR 6X1L 5,40\n");
        $this->postReceipt([new UploadedFile($path, 'ticket.txt', 'text/plain', null, true)]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }

    /** @return array<string, mixed> */
    private function leclercReceipt(): array
    {
        return [
            'store' => 'E.LECLERC',
            'receiptDate' => '2026-10-20',
            'total' => 42.5,
            'lines' => [
                [
                    'rawLabel' => 'LAIT 1/2 ECR 6X1L',
                    'expandedName' => 'Lait demi-écrémé',
                    'isFood' => true,
                    'quantity' => 1,
                    'packageQuantity' => 1,
                    'packageUnit' => 'l',
                    'lineTotal' => 5.4,
                ],
                [
                    'rawLabel' => 'COURGETTES 0,842KG',
                    'expandedName' => 'Courgettes',
                    'isFood' => true,
                    'quantity' => 2,
                    'packageQuantity' => 0.842,
                    'packageUnit' => 'kg',
                    'lineTotal' => 1.68,
                ],
                [
                    'rawLabel' => 'PILES AA X4',
                    'expandedName' => 'Piles',
                    'isFood' => false,
                    'quantity' => 1,
                    'packageQuantity' => 0,
                    'packageUnit' => '',
                    'lineTotal' => 4.99,
                ],
            ],
        ];
    }

    /**
     * The model's answer to the next receipt read.
     *
     * @param array<string, mixed> $payload
     */
    private function fakeReceipt(array $payload): void
    {
        $generator = $this->createMock(RecipeGeneratorInterface::class);
        $generator
            ->method('generate')
            ->with(self::anything(), self::anything(), self::anything(), self::countOf(1))
            ->willReturn(json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));

        $this->freshClient($generator);
    }

    /**
     * A client on a fresh kernel, with the model faked when one is given.
     *
     * The browser reboots the kernel before every request but the first, and
     * a reboot rebuilds the container: a fake set on the old one is lost, and
     * keeping the kernel alive instead would carry the request-scoped
     * household from one request to the next. One kernel per request, then.
     */
    private function freshClient(?RecipeGeneratorInterface $generator = null): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();

        if (null !== $generator) {
            $factory = $this->createMock(RecipeGeneratorFactory::class);
            $factory->method('create')->willReturn($generator);
            static::getContainer()->set(RecipeGeneratorFactory::class, $factory);
        }
    }

    /** @return array<string, mixed> */
    private function readReceipt(): array
    {
        $this->postReceipt([$this->photo()]);

        return $this->decode(Response::HTTP_OK);
    }

    /** @param list<UploadedFile> $files */
    private function postReceipt(array $files): void
    {
        $this->client->request(
            'POST',
            '/api/grocery-returns/receipt',
            server: $this->server,
            files: [] !== $files ? ['images' => $files] : [],
        );
    }

    private function photo(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ticket');
        file_put_contents($path, base64_decode(self::PNG, true));

        return new UploadedFile($path, 'ticket.png', 'image/png', null, true);
    }

    /** @return array<string, mixed> */
    private function decode(int $expectedStatus): array
    {
        $response = $this->client->getResponse();
        self::assertSame($expectedStatus, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
