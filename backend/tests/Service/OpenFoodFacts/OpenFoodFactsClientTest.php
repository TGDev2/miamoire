<?php

declare(strict_types=1);

namespace App\Tests\Service\OpenFoodFacts;

use App\Service\OpenFoodFacts\OpenFoodFactsClient;
use App\Service\OpenFoodFacts\OpenFoodFactsUnavailableException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * « Open Food Facts ne connaît pas ce produit » et « Open Food Facts n'a pas
 * répondu » ne disent pas la même chose. Le premier est définitif et ouvre la
 * saisie manuelle ; le second se réessaie. Les confondre ajoutait au panier des
 * lignes vides pour des produits parfaitement connus, puis apprenait au
 * catalogue partagé une fiche manuelle à leur place.
 */
class OpenFoodFactsClientTest extends TestCase
{
    public function testAnUnknownProductIsNull(): void
    {
        $client = $this->client(new MockResponse(
            '{"status":"failure","result":{"id":"product_not_found"}}',
            ['http_code' => 404, 'response_headers' => ['content-type' => 'application/json']],
        ));

        self::assertNull($client->fetchProduct('3017620422003'));
    }

    public function testAKnownProductIsHydrated(): void
    {
        $client = $this->client($this->json(200, [
            'status' => 'success',
            'product' => ['product_name_fr' => 'Pâte à tartiner', 'brands' => 'Ferrero, Nutella'],
        ]));

        $product = $client->fetchProduct('3017620422003');

        self::assertNotNull($product);
        self::assertSame('Pâte à tartiner', $product->name);
        self::assertSame('Ferrero', $product->brand);
    }

    /** Une fiche sans nom n'apporte rien de plus qu'un code nu : c'est un produit inconnu, pas une panne. */
    public function testAProductWithoutANameIsNull(): void
    {
        $client = $this->client($this->json(200, [
            'status' => 'success',
            'product' => ['product_name' => '  ', 'brands' => 'Marque'],
        ]));

        self::assertNull($client->fetchProduct('3017620422003'));
    }

    /** @return iterable<string, array{MockResponse}> */
    public static function outages(): iterable
    {
        yield 'quota atteint' => [new MockResponse('{"status":"failure"}', ['http_code' => 429])];
        yield 'erreur serveur' => [new MockResponse('{"status":"failure"}', ['http_code' => 500])];
        yield 'passerelle en HTML' => [new MockResponse('<html>502 Bad Gateway</html>', ['http_code' => 502])];
        yield 'portail captif en 200' => [new MockResponse('<html>Connectez-vous</html>', ['http_code' => 200])];
        yield 'réseau coupé' => [new MockResponse('', ['error' => 'Could not resolve host: world.openfoodfacts.org'])];
    }

    #[DataProvider('outages')]
    public function testAnOutageIsNeverMistakenForAnUnknownProduct(MockResponse $response): void
    {
        $client = $this->client($response);

        $this->expectException(OpenFoodFactsUnavailableException::class);
        $client->fetchProduct('3017620422003');
    }

    /** @param array<string, mixed> $body */
    private function json(int $status, array $body): MockResponse
    {
        return new MockResponse(
            json_encode($body, \JSON_THROW_ON_ERROR),
            ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']],
        );
    }

    private function client(MockResponse $response): OpenFoodFactsClient
    {
        return new OpenFoodFactsClient(
            new MockHttpClient($response),
            new NullLogger(),
            'https://world.openfoodfacts.org',
            'Food/test',
        );
    }
}
