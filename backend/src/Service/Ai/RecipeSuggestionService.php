<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Household;
use App\Enum\ProductTrait;
use App\Exception\StockException;
use App\Service\Ai\Generator\RecipeGeneratorFactory;
use App\Service\Ai\Safety\DietaryGuardFactory;
use App\Service\Ai\Safety\RecipeSafetyFilter;
use App\Service\Inventory\InventoryAggregator;
use App\Service\Inventory\InventoryLine;

/**
 * Génération de propositions culinaires à partir de l'inventaire réel.
 *
 * L'IA ne modifie JAMAIS le stock : elle renvoie une proposition de
 * consommation que l'utilisateur devra confirmer explicitement
 * (voir RecipeConfirmationService).
 *
 * Les exclusions alimentaires ne reposent plus sur la seule bonne volonté du
 * modèle. Elles sont appliquées deux fois, et cette redondance est délibérée :
 *
 * - **avant** — un aliment exclu ne figure pas dans l'inventaire transmis ;
 * - **après** — chaque recette renvoyée est relue, et celles qui nomment un
 *   aliment exclu sont écartées, y compris quand il n'apparaît qu'à l'étape 4.
 *
 * La consigne au modèle demeure : elle évite de gaspiller des propositions.
 * Mais c'est le contrôle qui garantit, pas la consigne.
 *
 * Le fournisseur de modèle est interchangeable — voir RecipeGeneratorFactory.
 */
class RecipeSuggestionService
{
    public function __construct(
        private readonly RecipeGeneratorFactory $generators,
        private readonly InventoryAggregator $aggregator,
        private readonly DietaryGuardFactory $guards,
        private readonly RecipeSafetyFilter $safetyFilter,
        private readonly RequiredIngredientFilter $requiredFilter,
    ) {
    }

    /** @return array<string, mixed> */
    public function suggest(Household $household, RecipeRequest $request): array
    {
        // Lève une erreur explicite si aucune clé n'est configurée.
        $generator = $this->generators->create();

        $guard = $this->guards->for($household, $request->exclusions);
        $inventory = $this->aggregator->aggregate($household, guard: $guard);

        if ([] === $inventory) {
            throw new StockException($guard->isEmpty()
                ? 'Votre inventaire est vide : ajoutez au moins un aliment avant de demander une recette.'
                : 'Aucun aliment de votre inventaire ne respecte les exclusions du foyer.');
        }

        $required = $this->resolveRequired($inventory, $request->priorityKey);
        $inventoryPayload = array_map(static fn (InventoryLine $line) => $line->toAiPayload(), $inventory);

        $payload = [
            'people' => $request->people,
            'mealType' => $request->mealType,
            'maxPreparationMinutes' => $request->maxMinutes,
            'availableEquipment' => $request->equipment,
            'dietaryExclusions' => $guard->exclusions(),
            'allowExtraIngredients' => $request->allowExtraIngredients,
            'mustUseIngredient' => null !== $required
                ? ['key' => $required->key, 'ingredient' => $required->label]
                : null,
            'inventory' => $inventoryPayload,
        ];

        $raw = $generator->generate(
            $this->systemPrompt($guard->exclusions(), $required),
            json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
            RecipeSchema::definition(),
        );

        try {
            /** @var array{recipes?: list<array<string, mixed>>} $decoded */
            $decoded = json_decode($this->stripCodeFence($raw), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new StockException('Réponse illisible du générateur de recettes. Réessayez.');
        }

        $checked = $this->safetyFilter->apply(
            array_slice($decoded['recipes'] ?? [], 0, 3),
            $guard,
            $this->labelsByKey($inventory),
        );

        // Second contrôle, de nature différente : l'aliment imposé. Il s'applique
        // à ce qui a survécu au premier — une recette écartée pour un allergène
        // n'a pas à être écartée une seconde fois pour un autre motif.
        $constrained = $this->requiredFilter->apply($checked['recipes'], $required);

        $rejected = array_merge($checked['rejected'], $constrained['rejected']);

        // Une liste vide sans explication laisserait croire à une panne. C'est au
        // contraire le contrôle qui a fonctionné : il faut le dire.
        if ([] === $constrained['recipes'] && [] !== $rejected) {
            throw new StockException(sprintf(
                'Les %d propositions reçues ont toutes été écartées (%s) : aucune ne vous est montrée. Relancez une génération.',
                count($rejected),
                implode(', ', array_unique(array_column($rejected, 'reason'))),
            ));
        }

        return [
            'recipes' => $constrained['recipes'],
            'rejected' => $rejected,
            'inventory' => $inventoryPayload,
            'guaranteedExclusions' => $guard->exclusions(),
            'requiredIngredient' => null !== $required
                ? ['key' => $required->key, 'label' => $required->label]
                : null,
            'provider' => $generator->label(),
        ];
    }

    /**
     * L'aliment que les recettes devront utiliser, s'il en a été désigné un.
     *
     * Une clé inconnue est une erreur explicite et non un silence : elle vient
     * d'un inventaire que l'application affichait encore alors que le lot venait
     * d'être épuisé ou écarté par une exclusion. Générer trois recettes en
     * ignorant la contrainte serait la pire des réponses.
     *
     * @param list<InventoryLine> $inventory
     */
    private function resolveRequired(array $inventory, ?string $priorityKey): ?InventoryLine
    {
        $key = null !== $priorityKey ? trim($priorityKey) : '';

        if ('' === $key) {
            return null;
        }

        foreach ($inventory as $line) {
            if ($line->key === $key) {
                return $line;
            }
        }

        throw new StockException(
            'L\'aliment à utiliser en priorité n\'est plus disponible dans votre inventaire. '
            .'Rafraîchissez l\'écran et choisissez-en un autre.',
        );
    }

    /**
     * @param list<InventoryLine> $inventory
     *
     * @return array<string, string>
     */
    private function labelsByKey(array $inventory): array
    {
        $labels = [];

        foreach ($inventory as $line) {
            $labels[$line->key] = $line->label;
        }

        return $labels;
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

    /** @param list<string> $exclusions */
    private function systemPrompt(array $exclusions, ?InventoryLine $required): string
    {
        $units = implode(', ', array_map(static fn (\App\Enum\Unit $u) => $u->value, \App\Enum\Unit::cases()));
        $traits = $this->traitGlossary();

        $imposed = null === $required
            ? ''
            : sprintf(
                "\n            ALIMENT IMPOSÉ : « %s » (clé `%s`). Les TROIS recettes doivent l'employer,\n"
                ."            et sa clé doit figurer dans leur `stockConsumptionProposal`. Un programme\n"
                ."            vérifie cette clé sur chaque recette et supprime celles qui ne l'utilisent\n"
                ."            pas : une proposition sans cet aliment est une proposition perdue.\n",
                $required->label,
                $required->key,
            );

        $safety = [] === $exclusions
            ? ''
            : sprintf(
                "\n            CONTRÔLE AUTOMATIQUE : chaque recette que tu renvoies est relue par un\n"
                ."            programme qui cherche %s dans son titre, son résumé, ses ingrédients,\n"
                ."            ses substitutions ET chacune de ses étapes. Toute recette où l'un de ces\n"
                ."            termes apparaît est supprimée avant d'atteindre l'utilisateur, sans appel.\n"
                ."            Une recette écartée est une proposition perdue : n'en propose aucune qui\n"
                ."            s'en approche, y compris en accompagnement ou en suggestion de service.\n",
                implode(', ', $exclusions),
            );

        return <<<PROMPT
            Tu es un assistant culinaire intégré à une application de gestion de stock alimentaire.
            Tu proposes exactement 3 recettes réalisables à partir d'un inventaire réel, en français.

            Données reçues : le nombre de personnes, le type de repas, le temps disponible,
            les équipements utilisables, les exclusions alimentaires, l'autorisation ou non
            d'ajouter des ingrédients manquants, et l'inventaire du foyer.

            Chaque ligne d'inventaire porte une clé stable `key`, un libellé, une quantité,
            une unité et un indicateur `expiresSoon`. Elle peut aussi porter `alreadyOpened`,
            `traits`, `category`, `notes`, `quantityIsEstimated` et `expirationIsEstimated`,
            qui décrivent l'état réel de l'aliment et la confiance qu'on peut lui accorder.

            Signification des `traits` :

            {$traits}

            Règles impératives :

            1. Priorise les ingrédients dont `expiresSoon` vaut true : ils doivent apparaître
               dans au moins deux des trois propositions quand c'est culinairement cohérent.
               Puis, à égalité, ceux dont `alreadyOpened` vaut true : un paquet entamé se
               dégrade pendant qu'un paquet scellé attend. La date passe toujours devant.
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
            7. N'utilise jamais un ingrédient figurant dans `dietaryExclusions`, ni aucun de ses
               dérivés : pas de beurre de cacahuète si l'arachide est exclue, pas de semoule si
               le gluten l'est.
            8. Les `traits` et les `notes` décrivent l'aliment tel qu'il est réellement dans
               le placard. N'écris JAMAIS une étape de préparation déjà faite : des pommes de
               terre `canned` ne s'épluchent pas et ne se cuisent pas trente minutes, elles
               s'égouttent et se réchauffent. Adapte aussi le temps de préparation en
               conséquence.
            9. `quantityIsEstimated` signale une quantité approximative : ne bâtis pas une
               recette qui exigerait cette quantité au gramme près, et reste en deçà.
            {$imposed}{$safety}
            À propos de `stockConsumptionProposal` :

            - C'est une PROPOSITION de retrait de stock, soumise ensuite à validation humaine.
            - Le champ `key` doit reprendre EXACTEMENT une clé `key` de l'inventaire reçu.
              N'invente jamais de clé et n'y mets jamais un ingrédient à acheter.
            - Une clé désigne un aliment DANS UN ÉTAT PRÉCIS : `tomate` et `tomate-conserve`
              sont deux stocks différents, jamais interchangeables.
            - `quantity` ne doit jamais dépasser la quantité disponible pour cette clé.
            - `unit` doit appartenir à : {$units}, et rester compatible avec l'unité
              de l'inventaire (une masse ne devient pas un volume).
            - N'y fais figurer chaque clé qu'UNE SEULE FOIS, en cumulant les usages de la
              recette : deux lignes pour le même ingrédient seraient fusionnées de toute façon.
            - N'y fais figurer que ce qui est réellement consommé par la recette.

            Rédige des étapes courtes et concrètes. Réponds uniquement par le JSON demandé,
            sans texte d'introduction ni bloc de code.
            PROMPT;
    }
}
