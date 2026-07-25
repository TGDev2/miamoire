<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Household;
use App\Enum\ProductTrait;
use App\Exception\StockException;
use App\Service\Ai\Generator\RecipeGeneratorFactory;
use App\Service\Inventory\InventoryAggregator;

/**
 * Génération de propositions culinaires à partir de l'inventaire réel.
 *
 * L'IA ne modifie JAMAIS le stock : elle renvoie une proposition de
 * consommation que l'utilisateur devra confirmer explicitement
 * (voir RecipeConfirmationService).
 *
 * Le fournisseur de modèle est interchangeable — voir RecipeGeneratorFactory.
 */
class RecipeSuggestionService
{
    public function __construct(
        private readonly RecipeGeneratorFactory $generators,
        private readonly InventoryAggregator $aggregator,
    ) {
    }

    /** @return array{recipes: list<array<string, mixed>>, inventory: list<array<string, mixed>>, provider: string} */
    public function suggest(Household $household, RecipeRequest $request): array
    {
        // Lève une erreur explicite si aucune clé n'est configurée.
        $generator = $this->generators->create();

        $inventory = $this->aggregator->aggregate($household);

        if ([] === $inventory) {
            throw new StockException('Votre inventaire est vide : ajoutez au moins un aliment avant de demander une recette.');
        }

        $inventoryPayload = array_map(static fn ($line) => $line->toAiPayload(), $inventory);

        $payload = [
            'people' => $request->people,
            'mealType' => $request->mealType,
            'maxPreparationMinutes' => $request->maxMinutes,
            'availableEquipment' => $request->equipment,
            'dietaryExclusions' => $request->exclusions,
            'allowExtraIngredients' => $request->allowExtraIngredients,
            'inventory' => $inventoryPayload,
        ];

        $raw = $generator->generate(
            $this->systemPrompt(),
            json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
            RecipeSchema::definition(),
        );

        try {
            /** @var array{recipes?: list<array<string, mixed>>} $decoded */
            $decoded = json_decode($this->stripCodeFence($raw), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new StockException('Réponse illisible du générateur de recettes. Réessayez.');
        }

        return [
            'recipes' => array_slice($decoded['recipes'] ?? [], 0, 3),
            'inventory' => $inventoryPayload,
            'provider' => $generator->label(),
        ];
    }

    /**
     * Le glossaire est construit depuis l'enum : ajouter un trait suffit à
     * l'enseigner au modèle, sans toucher au prompt.
     */
    private function traitGlossary(): string
    {
        return implode("\n", array_map(
            static fn (ProductTrait $trait) => sprintf('- `%s` (%s) : %s.', $trait->value, $trait->label(), $trait->instruction()),
            ProductTrait::cases(),
        ));
    }

    /** Certains modèles encadrent le JSON par ```json malgré la consigne. */
    private function stripCodeFence(string $raw): string
    {
        $trimmed = trim($raw);

        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $trimmed) ?? $trimmed;
        }

        return trim($trimmed);
    }

    private function systemPrompt(): string
    {
        $units = implode(', ', array_map(static fn (\App\Enum\Unit $u) => $u->value, \App\Enum\Unit::cases()));
        $traits = $this->traitGlossary();

        return <<<PROMPT
            Tu es un assistant culinaire intégré à une application de gestion de stock alimentaire.
            Tu proposes exactement 3 recettes réalisables à partir d'un inventaire réel, en français.

            Données reçues : le nombre de personnes, le type de repas, le temps disponible,
            les équipements utilisables, les exclusions alimentaires, l'autorisation ou non
            d'ajouter des ingrédients manquants, et l'inventaire du foyer.

            Chaque ligne d'inventaire porte une clé stable `key`, un libellé, une quantité,
            une unité et un indicateur `expiresSoon`. Elle peut aussi porter `traits`,
            `category` et `notes`, qui décrivent l'état réel de l'aliment.

            Signification des `traits` :

            {$traits}

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
            8. Les `traits` et les `notes` décrivent l'aliment tel qu'il est réellement dans
               le placard. N'écris JAMAIS une étape de préparation déjà faite : des pommes de
               terre `canned` ne s'épluchent pas et ne se cuisent pas trente minutes, elles
               s'égouttent et se réchauffent. Adapte aussi le temps de préparation en
               conséquence.

            À propos de `stockConsumptionProposal` :

            - C'est une PROPOSITION de retrait de stock, soumise ensuite à validation humaine.
            - Le champ `key` doit reprendre EXACTEMENT une clé `key` de l'inventaire reçu.
              N'invente jamais de clé et n'y mets jamais un ingrédient à acheter.
            - `quantity` ne doit jamais dépasser la quantité disponible pour cette clé.
            - `unit` doit appartenir à : {$units}, et rester compatible avec l'unité
              de l'inventaire (une masse ne devient pas un volume).
            - N'y fais figurer que ce qui est réellement consommé par la recette.

            Rédige des étapes courtes et concrètes. Réponds uniquement par le JSON demandé,
            sans texte d'introduction ni bloc de code.
            PROMPT;
    }
}
