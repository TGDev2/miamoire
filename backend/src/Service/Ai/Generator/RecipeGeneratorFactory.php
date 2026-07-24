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
        $generator = match (strtolower(trim($this->provider))) {
            self::PROVIDER_CLAUDE => $this->claude,
            self::PROVIDER_GEMINI, '' => $this->gemini,
            default => throw new StockException(sprintf(
                'Fournisseur de recettes « %s » inconnu. Valeurs acceptées : %s, %s.',
                $this->provider,
                self::PROVIDER_GEMINI,
                self::PROVIDER_CLAUDE,
            )),
        };

        if (!$generator->isConfigured()) {
            throw new StockException(sprintf(
                'Le générateur de recettes (%s) n\'est pas configuré : renseignez %s dans backend/.env.local.',
                $generator->label(),
                $generator->configurationHint(),
            ));
        }

        return $generator;
    }
}
