<?php

declare(strict_types=1);

namespace App\Service\Ai\Generator;

use Psr\Log\LoggerInterface;

/**
 * Une suite ordonnée de tentatives, essayées jusqu'à la première qui répond.
 *
 * Le palier gratuit de Gemini compte ses quotas par projet ET par modèle — le
 * 429 le dit dans son propre corps (`GenerateRequestsPerDayPerProjectPerModel`).
 * Un modèle épuisé n'épuise donc pas le suivant : le secours ne coûte rien, il
 * suffisait de le brancher.
 *
 * La chaîne n'avance que sur `RecipeGeneratorUnavailableException`. Une clé
 * invalide ou un modèle inexistant arrête tout, et c'est voulu : les rejouer
 * ailleurs ne ferait que retarder le seul message qui aide l'utilisateur.
 */
class FallbackRecipeGenerator implements RecipeGeneratorInterface
{
    // Le seul recours qui existe vraiment quand plus rien ne génère : la liste
    // est déjà sur l'écran « Cuisiner », autant la nommer.
    private const SAVED_RECIPES_HINT = 'Vos recettes enregistrées restent accessibles depuis l\'onglet Cuisiner.';

    /** @param non-empty-list<RecipeGeneratorInterface> $generators */
    public function __construct(
        private readonly array $generators,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->first()->isConfigured();
    }

    public function label(): string
    {
        return $this->first()->label();
    }

    public function configurationHint(): string
    {
        return $this->first()->configurationHint();
    }

    public function generate(string $systemPrompt, string $userPayload, array $schema, array $images = []): string
    {
        $attempts = \count($this->generators);
        $last = null;

        foreach ($this->generators as $index => $generator) {
            try {
                return $generator->generate($systemPrompt, $userPayload, $schema, $images);
            } catch (RecipeGeneratorUnavailableException $exception) {
                $last = $exception;

                $this->logger->warning('Tentative de génération indisponible, passage à la suivante', [
                    'attempt' => $index + 1,
                    'attempts' => $attempts,
                    'provider' => $generator->label(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $fallbacks = $attempts - 1;

        $summary = match (true) {
            0 === $fallbacks => '',
            1 === $fallbacks => ' L\'option de secours a échoué elle aussi.',
            default => sprintf(' Les %d options de secours ont échoué elles aussi.', $fallbacks),
        };

        // `$last` ne peut pas être nul : la liste est non vide, donc soit une
        // tentative a rendu un texte, soit elle a levé cette exception.
        throw new RecipeGeneratorUnavailableException(
            sprintf('%s%s %s', $last?->getMessage(), $summary, self::SAVED_RECIPES_HINT),
            previous: $last,
        );
    }

    private function first(): RecipeGeneratorInterface
    {
        return $this->generators[array_key_first($this->generators)];
    }
}
