<?php

declare(strict_types=1);

namespace App\Service\Ai\Generator;

use App\Exception\StockException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Google Gemini — palier gratuit, sans carte bancaire.
 *
 * L'endpoint « interactions » accepte un schéma JSON standard : le même
 * RecipeSchema sert donc aux deux fournisseurs, sans conversion.
 */
class GeminiRecipeGenerator implements RecipeGeneratorInterface
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/interactions';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
        private readonly string $model,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->apiKey);
    }

    public function label(): string
    {
        return 'Google Gemini';
    }

    public function configurationHint(): string
    {
        return 'GEMINI_API_KEY';
    }

    public function generate(string $systemPrompt, string $userPayload, array $schema): string
    {
        try {
            $response = $this->httpClient->request('POST', self::ENDPOINT, [
                'headers' => [
                    'x-goog-api-key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'system_instruction' => $systemPrompt,
                    'input' => $userPayload,
                    'response_format' => [
                        'type' => 'text',
                        'mime_type' => 'application/json',
                        'schema' => $schema,
                    ],
                ],
                'timeout' => 90,
            ]);

            $status = $response->getStatusCode();
            /** @var array<string, mixed> $payload */
            $payload = $response->toArray(false);
        } catch (ExceptionInterface $exception) {
            $this->logger->error('Appel Gemini impossible', ['error' => $exception->getMessage()]);

            throw new StockException(
                'Le service de recettes est injoignable. Vérifiez votre connexion et réessayez.',
            );
        }

        if ($status >= 400) {
            $message = $payload['error']['message'] ?? 'erreur inconnue';
            $this->logger->error('Gemini a refusé la requête', ['status' => $status, 'body' => $payload]);

            throw new StockException(match ($status) {
                400 => 'Requête refusée par Gemini. Vérifiez que GEMINI_MODEL désigne un modèle existant.',
                401, 403 => 'Clé GEMINI_API_KEY invalide ou sans accès à ce modèle.',
                429 => 'Quota gratuit Gemini atteint. Réessayez dans quelques minutes.',
                default => sprintf('Le générateur de recettes a échoué (%d) : %s', $status, $message),
            });
        }

        $text = $this->extractText($payload);

        if (null === $text) {
            $this->logger->error('Réponse Gemini inexploitable', [
                'status' => $payload['status'] ?? null,
                'body' => $payload,
            ]);

            throw new StockException('Réponse inexploitable du générateur de recettes.');
        }

        return $text;
    }

    /**
     * Le texte vit dans `steps[].content[].text`.
     *
     * `output_text` est une commodité des SDK, absente de la réponse REST ; le
     * premier step est un `thought` sans contenu, d'où le parcours complet plutôt
     * qu'un accès direct au premier élément.
     *
     * @param array<string, mixed> $payload
     */
    private function extractText(array $payload): ?string
    {
        // Conservé au cas où l'API exposerait un jour ce raccourci.
        if (is_string($payload['output_text'] ?? null) && '' !== trim($payload['output_text'])) {
            return $payload['output_text'];
        }

        if (!is_array($payload['steps'] ?? null)) {
            return null;
        }

        $latest = null;

        foreach ($payload['steps'] as $step) {
            if (!is_array($step) || !is_array($step['content'] ?? null)) {
                continue;
            }

            $parts = [];
            foreach ($step['content'] as $block) {
                if (is_array($block) && 'text' === ($block['type'] ?? null) && is_string($block['text'] ?? null)) {
                    $parts[] = $block['text'];
                }
            }

            // On retient le dernier bloc de texte produit par le modèle.
            if ([] !== $parts) {
                $latest = implode('', $parts);
            }
        }

        return null !== $latest && '' !== trim($latest) ? $latest : null;
    }
}
