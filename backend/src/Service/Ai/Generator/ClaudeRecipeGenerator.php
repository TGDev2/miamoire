<?php

declare(strict_types=1);

namespace App\Service\Ai\Generator;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\RateLimitException;
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

        // Saturation ou panne : la tentative suivante de la chaîne peut réussir.
        // Le SDK a déjà appliqué sa propre politique de réessai avant d'en
        // arriver là, il n'y a donc rien à temporiser ici.
        try {
            $message = $this->call($arguments);
        } catch (RateLimitException) {
            throw new RecipeGeneratorUnavailableException(
                'Trop de requêtes envoyées à Claude. Réessayez dans quelques instants.',
            );
        } catch (InternalServerException $exception) {
            throw new RecipeGeneratorUnavailableException('Claude est momentanément indisponible.', previous: $exception);
        } catch (APIConnectionException $exception) {
            // APITimeoutException en hérite : un délai dépassé passe par ici.
            throw new RecipeGeneratorUnavailableException(
                'Le service de recettes est injoignable. Vérifiez votre connexion et réessayez.',
                previous: $exception,
            );
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

    /**
     * L'appel au modèle, avec repli si la bêta n'est pas activée sur la clé.
     *
     * Le `catch` était initialement posé sur `APIException`, qui est la racine
     * de toute la hiérarchie du SDK : une limite de débit ou un réseau coupé s'y
     * lisait donc comme « bêta absente », se faisait journaliser ainsi, puis
     * rejouer aussitôt — pour échouer une seconde fois, hors de tout `catch`.
     * Ces cas-là remontent désormais intacts ; seul un vrai refus lié à la bêta
     * déclenche le repli.
     *
     * @param array<string, mixed> $arguments
     */
    private function call(array $arguments): object
    {
        try {
            // Les classificateurs de sûreté peuvent refuser une requête ; le
            // paramètre `fallbacks` la rejoue automatiquement sur un autre modèle.
            return $this->client->beta->messages->create(...[
                ...$arguments,
                'betas' => [self::FALLBACK_BETA],
                'fallbacks' => 'default',
            ]);
        } catch (RateLimitException|InternalServerException|APIConnectionException $exception) {
            throw $exception;
        } catch (APIException $exception) {
            // Si la bêta n'est pas activée sur la clé, on reste fonctionnel sans elle.
            $this->logger->warning('Repli sur l\'appel standard sans fallbacks serveur', [
                'error' => $exception->getMessage(),
            ]);

            return $this->client->messages->create(...$arguments);
        }
    }
}
