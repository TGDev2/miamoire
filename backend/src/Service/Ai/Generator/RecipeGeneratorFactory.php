<?php

declare(strict_types=1);

namespace App\Service\Ai\Generator;

use App\Exception\StockException;

/** Choisit le fournisseur de modèle déclaré dans RECIPE_PROVIDER. */
class RecipeGeneratorFactory
{
    public const PROVIDER_GEMINI = 'gemini';
    public const PROVIDER_CLAUDE = 'claude';

    public function __construct(
        private readonly GeminiRecipeGenerator $gemini,
        private readonly ClaudeRecipeGenerator $claude,
        private readonly string $provider,
    ) {
    }

    public function create(): RecipeGeneratorInterface
    {
        $generator = $this->selected();

        if (!$generator->isConfigured()) {
            // Le message nomme la variable sans présumer d'où elle vient : en
            // développement c'est backend/.env.local, en production une variable
            // d'environnement de l'hébergeur.
            throw new StockException(sprintf(
                'Le générateur de recettes (%s) n\'est pas configuré : définissez la variable %s sur le serveur (backend/.env.local en développement, variables d\'environnement de l\'hébergeur en production).',
                $generator->label(),
                $generator->configurationHint(),
            ));
        }

        return $generator;
    }

    /**
     * Le générateur désigné par RECIPE_PROVIDER, configuré ou non.
     *
     * Séparé de `create()` pour la sonde de santé : elle a besoin de savoir si
     * la clé du fournisseur actif est présente, jamais de lever l'exception que
     * `create()` réserve à un usage réel (génération de recettes).
     */
    public function selected(): RecipeGeneratorInterface
    {
        return match (strtolower(trim($this->provider))) {
            self::PROVIDER_CLAUDE => $this->claude,
            self::PROVIDER_GEMINI, '' => $this->gemini,
            default => throw new StockException(sprintf(
                'Fournisseur de recettes « %s » inconnu. Valeurs acceptées : %s, %s.',
                $this->provider,
                self::PROVIDER_GEMINI,
                self::PROVIDER_CLAUDE,
            )),
        };
    }
}
