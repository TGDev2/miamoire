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
 *
 * `GEMINI_MODEL` accepte plusieurs modèles séparés par des virgules. Une seule
 * valeur se comporte comme avant ; plusieurs deviennent autant de tentatives,
 * assemblées par `RecipeGeneratorFactory` et parcourues par
 * `FallbackRecipeGenerator`. Chaque instance, elle, n'interroge qu'un modèle.
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

    /**
     * Les modèles déclarés, dans l'ordre : le premier est le principal.
     *
     * @return non-empty-list<string>
     */
    public function models(): array
    {
        $models = array_values(array_filter(
            array_map(trim(...), explode(',', $this->model)),
            static fn (string $model): bool => '' !== $model,
        ));

        // Une valeur vide reste envoyée telle quelle : Gemini répond alors un 400
        // qui nomme le problème, ce qu'une liste vide ne saurait pas faire.
        return [] !== $models ? $models : [trim($this->model)];
    }

    /** La même configuration, restreinte à un seul modèle. */
    public function withModel(string $model): self
    {
        return new self($this->httpClient, $this->logger, $this->apiKey, $model);
    }

    public function generate(string $systemPrompt, string $userPayload, array $schema): string
    {
        $model = $this->models()[0];

        try {
            $response = $this->httpClient->request('POST', self::ENDPOINT, [
                'headers' => [
                    'x-goog-api-key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
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
            $this->logger->error('Appel Gemini impossible', [
                'model' => $model,
                'error' => $exception->getMessage(),
            ]);

            // Injoignable n'est pas refusé : un autre modèle, ou un autre
            // fournisseur, peut très bien répondre.
            throw new RecipeGeneratorUnavailableException(
                'Le service de recettes est injoignable. Vérifiez votre connexion et réessayez.',
            );
        }

        if ($status >= 400) {
            $message = $payload['error']['message'] ?? 'erreur inconnue';
            $this->logger->error('Gemini a refusé la requête', [
                'model' => $model,
                'status' => $status,
                'body' => $payload,
            ]);

            // 429 et 5xx disent « pas ici, pas maintenant » : la chaîne continue.
            // 400, 401 et 403 disent « pas comme ça » : les rejouer ailleurs ne
            // ferait que retarder le seul message qui aide.
            throw match (true) {
                429 === $status => new RecipeGeneratorUnavailableException($this->quotaMessage($payload)),
                $status >= 500 => new RecipeGeneratorUnavailableException(sprintf(
                    'Gemini est momentanément indisponible (%d).',
                    $status,
                )),
                400 === $status => new StockException(
                    'Requête refusée par Gemini. Vérifiez que GEMINI_MODEL désigne un modèle existant.',
                ),
                401 === $status, 403 === $status => new StockException(
                    'Clé GEMINI_API_KEY invalide ou sans accès à ce modèle.',
                ),
                default => new StockException(sprintf(
                    'Le générateur de recettes a échoué (%d) : %s',
                    $status,
                    $message,
                )),
            };
        }

        $text = $this->extractText($payload);

        if (null === $text) {
            $this->logger->error('Réponse Gemini inexploitable', [
                'model' => $model,
                'status' => $payload['status'] ?? null,
                'body' => $payload,
            ]);

            throw new StockException('Réponse inexploitable du générateur de recettes.');
        }

        return $text;
    }

    /**
     * Quota du jour ou de la minute : deux attentes très différentes.
     *
     * Le `retryDelay` que Google joint au 429 est un piège — il annonce une
     * poignée de secondes même sur un quota journalier, qui ne repart qu'à
     * minuit. Le discriminant fiable est `quotaId`.
     *
     * @param array<string, mixed> $payload
     */
    private function quotaMessage(array $payload): string
    {
        $quotaId = $this->quotaId($payload);

        if (null !== $quotaId && str_contains($quotaId, 'PerDay')) {
            return 'Quota quotidien Gemini atteint : il repart à minuit, heure du Pacifique.';
        }

        return 'Quota Gemini dépassé : trop de requêtes en peu de temps. Réessayez dans quelques instants.';
    }

    /**
     * L'identifiant du quota dépassé, dans `error.details[].violations[]`.
     *
     * `details` est un tableau non ordonné, et parfois absent : on filtre sur
     * `@type`, jamais sur un index.
     *
     * @param array<string, mixed> $payload
     */
    private function quotaId(array $payload): ?string
    {
        $details = $payload['error']['details'] ?? null;

        if (!is_array($details)) {
            return null;
        }

        foreach ($details as $detail) {
            if (!is_array($detail) || !is_array($detail['violations'] ?? null)) {
                continue;
            }

            if (!str_ends_with((string) ($detail['@type'] ?? ''), 'google.rpc.QuotaFailure')) {
                continue;
            }

            foreach ($detail['violations'] as $violation) {
                if (is_array($violation) && is_string($violation['quotaId'] ?? null)) {
                    return $violation['quotaId'];
                }
            }
        }

        return null;
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
