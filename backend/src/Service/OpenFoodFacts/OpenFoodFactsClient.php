<?php

declare(strict_types=1);

namespace App\Service\OpenFoodFacts;

use App\Enum\Unit;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Accès à l'API v3 d'Open Food Facts (recommandée pour les nouvelles intégrations).
 *
 * La base est collaborative : un produit peut être absent, ou présent mais
 * incomplet. Toutes les lectures sont donc défensives et une saisie manuelle
 * reste toujours possible côté application.
 */
class OpenFoodFactsClient
{
    private const FIELDS = 'code,product_name,product_name_fr,brands,categories,image_front_url,image_url,quantity,product_quantity,product_quantity_unit,allergens_tags,traces_tags,ingredients_text_fr,ingredients_text';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $baseUrl,
        private readonly string $userAgent,
    ) {
    }

    /**
     * `null` est une réponse : Open Food Facts ne connaît pas ce code, ou n'en a
     * qu'une fiche sans nom. Tout ce qui n'est pas une réponse lève
     * `OpenFoodFactsUnavailableException`.
     *
     * @throws OpenFoodFactsUnavailableException
     */
    public function fetchProduct(string $barcode): ?OpenFoodFactsProduct
    {
        $url = sprintf('%s/api/v3/product/%s.json', rtrim($this->baseUrl, '/'), rawurlencode($barcode));

        try {
            $response = $this->httpClient->request('GET', $url, [
                'query' => ['fields' => self::FIELDS],
                'headers' => ['User-Agent' => $this->userAgent, 'Accept' => 'application/json'],
                'timeout' => 8,
            ]);

            $status = $response->getStatusCode();

            if (404 === $status) {
                return null;
            }

            // Un 200 en HTML — portail captif, page de maintenance — lève ici.
            /** @var array<string, mixed>|null $payload */
            $payload = 200 === $status ? $response->toArray(false) : null;
        } catch (ExceptionInterface|TransportException $exception) {
            throw $this->unavailable($barcode, $exception->getMessage(), $exception);
        }

        // Un 429 (quota de 15 req/min/IP) ou un 5xx ne dit rien du produit.
        if (null === $payload) {
            throw $this->unavailable($barcode, sprintf('Open Food Facts a répondu %d.', $status));
        }

        $product = $payload['product'] ?? null;
        if (!is_array($product) || [] === $product) {
            return null;
        }

        return $this->hydrate($barcode, $product);
    }

    private function unavailable(string $barcode, string $reason, ?\Throwable $previous = null): OpenFoodFactsUnavailableException
    {
        $this->logger->warning('Open Food Facts injoignable', ['barcode' => $barcode, 'error' => $reason]);

        return new OpenFoodFactsUnavailableException($reason, previous: $previous);
    }

    /** @param array<string, mixed> $data */
    private function hydrate(string $barcode, array $data): ?OpenFoodFactsProduct
    {
        $name = $this->firstNonEmptyString($data, ['product_name_fr', 'product_name']);
        if (null === $name) {
            // Sans nom, la fiche n'apporte rien de plus qu'un code-barres nu.
            return null;
        }

        [$quantity, $unit] = $this->parseQuantity($data);

        return new OpenFoodFactsProduct(
            barcode: $barcode,
            name: $name,
            brand: $this->firstListValue($this->firstNonEmptyString($data, ['brands'])),
            category: $this->firstListValue($this->firstNonEmptyString($data, ['categories'])),
            packageQuantity: $quantity,
            packageUnit: $unit,
            imageUrl: $this->firstNonEmptyString($data, ['image_front_url', 'image_url']),
            allergensTags: $this->stringList($data, 'allergens_tags'),
            tracesTags: $this->stringList($data, 'traces_tags'),
            ingredientsText: $this->firstNonEmptyString($data, ['ingredients_text_fr', 'ingredients_text']),
        );
    }

    /**
     * Les champs `_tags` d'Open Food Facts sont déjà des listes — pas de virgule
     * à couper comme pour `brands`/`categories`, juste une lecture défensive.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function stringList(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{0: float|null, 1: Unit|null}
     */
    private function parseQuantity(array $data): array
    {
        // Champ structuré : le plus fiable quand il est renseigné.
        $structured = $data['product_quantity'] ?? null;
        $structuredUnit = $this->normalizeUnit((string) ($data['product_quantity_unit'] ?? ''));
        if (is_numeric($structured) && null !== $structuredUnit && (float) $structured > 0) {
            return [(float) $structured, $structuredUnit];
        }

        // Sinon on lit le texte libre : « 500 g », « 1 L », « 6 x 125 g ».
        $raw = $this->firstNonEmptyString($data, ['quantity']);
        if (null === $raw) {
            return [null, null];
        }

        $normalized = str_replace(',', '.', mb_strtolower($raw));

        if (preg_match('/(\d+(?:\.\d+)?)\s*[x*]\s*(\d+(?:\.\d+)?)\s*([a-z]+)/u', $normalized, $matches)) {
            $unit = $this->normalizeUnit($matches[3]);

            return null === $unit ? [null, null] : [(float) $matches[1] * (float) $matches[2], $unit];
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*([a-z]+)/u', $normalized, $matches)) {
            $unit = $this->normalizeUnit($matches[2]);

            return null === $unit ? [null, null] : [(float) $matches[1], $unit];
        }

        return [null, null];
    }

    private function normalizeUnit(string $raw): ?Unit
    {
        return match (trim(mb_strtolower($raw))) {
            'g', 'gr', 'gramme', 'grammes' => Unit::Gram,
            'kg', 'kilo', 'kilos', 'kilogramme', 'kilogrammes' => Unit::Kilogram,
            'ml', 'millilitre', 'millilitres' => Unit::Milliliter,
            'cl', 'centilitre', 'centilitres' => Unit::Centiliter,
            'l', 'litre', 'litres' => Unit::Liter,
            'piece', 'pieces', 'pcs', 'unite', 'unites' => Unit::Piece,
            default => null,
        };
    }

    /** @param array<string, mixed> $data @param list<string> $keys */
    private function firstNonEmptyString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && '' !== trim($value)) {
                return trim($value);
            }
        }

        return null;
    }

    /** Open Food Facts renvoie des listes séparées par des virgules ; on garde la première. */
    private function firstListValue(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $first = trim(explode(',', $value)[0]);

        return '' === $first ? null : $first;
    }
}
