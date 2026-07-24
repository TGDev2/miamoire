<?php

declare(strict_types=1);

namespace App\Service\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use App\Entity\Household;
use App\Exception\StockException;
use App\Service\Inventory\InventoryAggregator;
use Psr\Log\LoggerInterface;

/**
 * Génération de propositions culinaires à partir de l'inventaire réel.
 *
 * L'IA ne modifie JAMAIS le stock : elle renvoie une proposition de
 * consommation que l'utilisateur devra confirmer explicitement
 * (voir RecipeConfirmationService).
 */
class RecipeSuggestionService
{
    private const MODEL = 'claude-opus-5';
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    public function __construct(
        private readonly Client $client,
        private readonly InventoryAggregator $aggregator,
        private readonly LoggerInterface $logger,
        private readonly string $effort,
        private readonly string $anthropicApiKey,
    ) {
    }

    /** @return array{recipes: list<array<string, mixed>>, inventory: list<array<string, mixed>>} */
    public function suggest(Household $household, RecipeRequest $request): array
    {
        if ('' === trim($this->anthropicApiKey)) {
            throw new StockException(
                'Le générateur de recettes n\'est pas configuré : renseignez ANTHROPIC_API_KEY dans backend/.env.local.',
            );
        }

        $inventory = $this->aggregator->aggregate($household);

        if ([] === $inventory) {
            throw new StockException('Votre inventaire est vide : ajoutez au moins un aliment avant de demander une recette.');
        }

        $payload = [
            'people' => $request->people,
            'mealType' => $request->mealType,
            'maxPreparationMinutes' => $request->maxMinutes,
            'availableEquipment' => $request->equipment,
            'dietaryExclusions' => $request->exclusions,
            'allowExtraIngredients' => $request->allowExtraIngredients,
            'inventory' => array_map(
                static fn ($line) => $line->toAiPayload(),
                $inventory,
            ),
        ];

        $raw = $this->callModel(json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));

        /** @var array{recipes?: list<array<string, mixed>>} $decoded */
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        return [
            'recipes' => array_slice($decoded['recipes'] ?? [], 0, 3),
            'inventory' => array_map(
                static fn ($line) => $line->toAiPayload(),
                $inventory,
            ),
        ];
    }

    private function callModel(string $userPayload): string
    {
        $arguments = [
            'model' => self::MODEL,
            'maxTokens' => 16000,
            'system' => [[
                'type' => 'text',
                'text' => $this->systemPrompt(),
                'cacheControl' => ['type' => 'ephemeral'],
            ]],
            'messages' => [['role' => 'user', 'content' => $userPayload]],
            'outputConfig' => [
                'effort' => $this->effort,
                'format' => [
                    'type' => 'json_schema',
                    'schema' => RecipeSchema::definition(),
                ],
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
            throw new StockException('Le générateur de recettes n\'a pas pu traiter cette demande. Reformulez vos contraintes.');
        }

        foreach ($message->content as $block) {
            if ('text' === $block->type) {
                return $block->text;
            }
        }

        throw new StockException('Réponse inexploitable du générateur de recettes.');
    }

    private function systemPrompt(): string
    {
        $units = implode(', ', array_map(static fn (\App\Enum\Unit $u) => $u->value, \App\Enum\Unit::cases()));

        return <<<PROMPT
            Tu es un assistant culinaire intégré à une application de gestion de stock alimentaire.
            Tu proposes exactement 3 recettes réalisables à partir d'un inventaire réel, en français.

            Données reçues : le nombre de personnes, le type de repas, le temps disponible,
            les équipements utilisables, les exclusions alimentaires, l'autorisation ou non
            d'ajouter des ingrédients manquants, et l'inventaire du foyer.

            Chaque ligne d'inventaire porte une clé stable `key`, un libellé, une quantité,
            une unité et un indicateur `expiresSoon`.

            Règles impératives :

            1. Priorise les ingrédients dont `expiresSoon` vaut true : ils doivent apparaître
               dans au moins deux des trois propositions quand c'est culinairement cohérent.
            2. Classe chaque recette par `feasibility` :
               - "ready" : tous les ingrédients essentiels sont dans l'inventaire ;
               - "adapted" : tu remplaces un ingrédient absent par un autre réellement disponible
                 (décris chaque remplacement dans `substitutions`) ;
               - "needs_shopping" : il manque un ou deux ingrédients clairement identifiés.
            3. Si `allowExtraIngredients` est false, ne propose aucune recette "needs_shopping" :
               `missingIngredients` doit rester vide. Les condiments de base présents dans
               toute cuisine (sel, poivre, eau) ne comptent jamais comme manquants.
            4. Distingue toujours l'essentiel du facultatif via le champ `essential`.
               L'absence de persil ne rend pas une recette impossible : marque-la `essential: false`.
            5. Adapte impérativement les quantités au nombre de personnes demandé et renseigne
               `servings` avec ce même nombre.
            6. Respecte `maxPreparationMinutes` et n'utilise que les équipements listés
               quand la liste n'est pas vide.
            7. N'utilise jamais un ingrédient figurant dans `dietaryExclusions`.

            À propos de `stockConsumptionProposal` :

            - C'est une PROPOSITION de retrait de stock, soumise ensuite à validation humaine.
            - Le champ `key` doit reprendre EXACTEMENT une clé `key` de l'inventaire reçu.
              N'invente jamais de clé et n'y mets jamais un ingrédient à acheter.
            - `quantity` ne doit jamais dépasser la quantité disponible pour cette clé.
            - `unit` doit appartenir à : {$units}, et rester compatible avec l'unité
              de l'inventaire (une masse ne devient pas un volume).
            - N'y fais figurer que ce qui est réellement consommé par la recette.

            Rédige des étapes courtes et concrètes. Reste factuel : pas d'introduction,
            pas de commentaire hors du schéma JSON demandé.
            PROMPT;
    }
}
