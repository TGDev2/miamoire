<?php

declare(strict_types=1);

namespace App\Service\Ai\Generator;

use App\Exception\StockException;
use Psr\Log\LoggerInterface;

/**
 * Assemble la suite de tentatives déclarée par RECIPE_PROVIDER et GEMINI_MODEL.
 *
 * Les deux variables sont des listes ordonnées, séparées par des virgules ; une
 * valeur unique se comporte exactement comme avant. `RECIPE_PROVIDER` enchaîne
 * les fournisseurs, `GEMINI_MODEL` les modèles d'un même fournisseur — et comme
 * le palier gratuit de Gemini compte ses quotas par modèle, un second modèle
 * suffit à donner un secours qui ne coûte rien.
 *
 * Un fournisseur sans clé est retiré de la liste plutôt que de la faire échouer :
 * nommer Claude en dernier recours ne doit rien casser tant que sa clé est absente.
 */
class RecipeGeneratorFactory
{
    public const PROVIDER_GEMINI = 'gemini';
    public const PROVIDER_CLAUDE = 'claude';

    public function __construct(
        private readonly GeminiRecipeGenerator $gemini,
        private readonly ClaudeRecipeGenerator $claude,
        private readonly LoggerInterface $logger,
        private readonly string $provider,
    ) {
    }

    /**
     * The same chain, declared by another variable — the receipts may go to
     * another provider than the recipes (a paid one, for the photos).
     */
    public function forProvider(string $provider): RecipeGeneratorInterface
    {
        return (new self($this->gemini, $this->claude, $this->logger, $provider))->create();
    }

    public function create(): RecipeGeneratorInterface
    {
        $attempts = array_values(array_filter(
            $this->attempts(),
            static fn (RecipeGeneratorInterface $generator): bool => $generator->isConfigured(),
        ));

        if ([] === $attempts) {
            $generator = $this->selected();

            // Le message nomme la variable sans présumer d'où elle vient : en
            // développement c'est backend/.env.local, en production une variable
            // d'environnement de l'hébergeur.
            throw new StockException(sprintf(
                'Le générateur de recettes (%s) n\'est pas configuré : définissez la variable %s sur le serveur (backend/.env.local en développement, variables d\'environnement de l\'hébergeur en production).',
                $generator->label(),
                $generator->configurationHint(),
            ));
        }

        // Une seule tentative n'a rien à enchaîner : inutile de l'emballer.
        return 1 === \count($attempts)
            ? $attempts[0]
            : new FallbackRecipeGenerator($attempts, $this->logger);
    }

    /**
     * La première tentative, configurée ou non.
     *
     * Séparé de `create()` pour la sonde de santé : elle a besoin de savoir si
     * la clé du fournisseur principal est présente, jamais de lever l'exception
     * que `create()` réserve à un usage réel (génération de recettes).
     */
    public function selected(): RecipeGeneratorInterface
    {
        return $this->expand($this->providers()[0])[0];
    }

    /**
     * Toutes les tentatives, dans l'ordre, clé présente ou non.
     *
     * @return non-empty-list<RecipeGeneratorInterface>
     */
    private function attempts(): array
    {
        $attempts = [];

        foreach ($this->providers() as $provider) {
            foreach ($this->expand($provider) as $generator) {
                $attempts[] = $generator;
            }
        }

        return $attempts;
    }

    /**
     * Les tentatives que porte un fournisseur : une par modèle chez Gemini.
     *
     * @return non-empty-list<RecipeGeneratorInterface>
     */
    private function expand(string $provider): array
    {
        return match ($provider) {
            self::PROVIDER_CLAUDE => [$this->claude],
            self::PROVIDER_GEMINI => array_map($this->gemini->withModel(...), $this->gemini->models()),
            default => throw new StockException(sprintf(
                'Fournisseur de recettes « %s » inconnu. Valeurs acceptées : %s, %s.',
                $provider,
                self::PROVIDER_GEMINI,
                self::PROVIDER_CLAUDE,
            )),
        };
    }

    /**
     * Les fournisseurs déclarés, dédoublonnés, dans l'ordre.
     *
     * @return non-empty-list<string>
     */
    private function providers(): array
    {
        $providers = array_values(array_unique(array_filter(
            array_map(
                static fn (string $provider): string => strtolower(trim($provider)),
                explode(',', $this->provider),
            ),
            static fn (string $provider): bool => '' !== $provider,
        )));

        // Une valeur vide ne doit pas casser l'application : on retombe sur le gratuit.
        return [] !== $providers ? $providers : [self::PROVIDER_GEMINI];
    }
}
