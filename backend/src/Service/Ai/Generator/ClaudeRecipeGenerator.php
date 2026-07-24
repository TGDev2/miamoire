<?php

declare(strict_types=1);

namespace App\Service\Ai\Generator;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use App\Exception\StockException;
use Psr\Log\LoggerInterface;

/** Claude (payant) — meilleure qualité de propositions, à activer via RECIPE_PROVIDER=claude. */
class ClaudeRecipeGenerator implements RecipeGeneratorInterface
{
    private const MODEL = 'claude-opus-5';
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    public function __construct(
        private readonly Client $client,
        private readonly LoggerInterface $logger,
        private readonly string $effort,
        private readonly string $apiKey,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->apiKey);
    }

    public function label(): string
    {
        return 'Claude';
    }

    public function configurationHint(): string
    {
        return 'ANTHROPIC_API_KEY';
    }

    public function generate(string $systemPrompt, string $userPayload, array $schema): string
    {
        $arguments = [
            'model' => self::MODEL,
            'maxTokens' => 16000,
            'system' => [[
                'type' => 'text',
                'text' => $systemPrompt,
                'cacheControl' => ['type' => 'ephemeral'],
            ]],
            'messages' => [['role' => 'user', 'content' => $userPayload]],
            'outputConfig' => [
                'effort' => $this->effort,
                'format' => ['type' => 'json_schema', 'schema' => $schema],
            ],
        ];

        try {
            // Les classificateurs de sûreté peuvent refuser une requête ; le
            // paramètre `fallbacks` la rejoue automatiquement sur un autre modèle.
            $message = $this->client->beta->messages->create(...[
                ...$arguments,
                'betas' => [self::FALLBACK_BETA],
                'fallbacks' => 'default',
            ]);
        } catch (APIException $exception) {
            // Si la bêta n'est pas activée sur la clé, on reste fonctionnel sans elle.
            $this->logger->warning('Repli sur l\'appel standard sans fallbacks serveur', [
                'error' => $exception->getMessage(),
            ]);

            $message = $this->client->messages->create(...$arguments);
        }

        if ('refusal' === $message->stopReason) {
            throw new StockException(
                'Le générateur de recettes n\'a pas pu traiter cette demande. Reformulez vos contraintes.',
            );
        }

        foreach ($message->content as $block) {
            if ('text' === $block->type) {
                return $block->text;
            }
        }

        throw new StockException('Réponse inexploitable du générateur de recettes.');
    }
}
