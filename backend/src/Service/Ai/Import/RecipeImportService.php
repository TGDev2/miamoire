<?php

declare(strict_types=1);

namespace App\Service\Ai\Import;

use App\Entity\Household;
use App\Exception\StockException;
use App\Repository\HouseholdProductVariantRepository;
use App\Service\Ai\RecipeNormalizer;
use App\Service\Ai\RecipePlan;
use App\Service\Ai\RecipePlanService;
use App\Service\Ai\Safety\DietaryGuardFactory;
use App\Service\Ai\Safety\RecipeSafetyFilter;
use App\Service\Inventory\InventoryAggregator;
use App\Service\Stock\ConsumptionLine;
use App\Service\Stock\ProposalConsolidator;

/**
 * Reads the recipes another assistant wrote, before the user keeps one.
 *
 * Nothing is written here: the user chooses which recipe to keep, and keeping
 * goes through `POST /api/recipes/saved` like any other recipe — which is what
 * makes an imported recipe one like the others, visible to the whole household
 * and planned again each time it is opened or cooked.
 *
 * What is checked again, and only this, is what the export prompt promises:
 * the household's exclusions (a recipe breaking one is refused), and what the
 * stock can supply. The required ingredient, the composition, the time and the
 * equipment are left to the other assistant — there is no request to hold them
 * to, and the prompt says so.
 */
class RecipeImportService
{
    /** More would be a conversation, not a choice — and each one is planned against the stock. */
    private const MAX_RECIPES = 10;

    public function __construct(
        private readonly RecipeBlockExtractor $extractor,
        private readonly ImportedLineResolver $resolver,
        private readonly RecipeNormalizer $normalizer,
        private readonly InventoryAggregator $aggregator,
        private readonly HouseholdProductVariantRepository $variants,
        private readonly DietaryGuardFactory $guards,
        private readonly RecipeSafetyFilter $safetyFilter,
        private readonly RecipePlanService $planner,
        private readonly ProposalConsolidator $consolidator,
    ) {
    }

    /**
     * @return array{
     *     recipes: list<array{recipe: array<string, mixed>, plan: RecipePlan, notices: list<string>}>,
     *     rejected: list<array{recipeName: string, reason: string}>,
     * }
     *
     * @throws StockException when the text holds no recipe that can be read
     */
    public function read(Household $household, string $text): array
    {
        ['recipes' => $candidates, 'unreadable' => $unreadable] = $this->extractor->extract($text);

        if ([] === $candidates) {
            throw new StockException(self::nothingToImport($unreadable));
        }

        $rejected = array_map(
            static fn (array $block): array => [
                'recipeName' => $block['recipeName'],
                'reason' => $block['truncated']
                    ? 'Son bloc d\'import est coupé : copiez-le en entier.'
                    : 'Son bloc d\'import est illisible : demandez à l\'assistant de le redonner en JSON strict.',
            ],
            $unreadable,
        );

        // Not guarded: an excluded food must be recognised to be refused.
        $inStock = [];
        foreach ($this->aggregator->aggregate($household) as $line) {
            $inStock[$line->key] = new KnownFood($line->key, $line->label, $line->unit->family(), true);
        }

        $guard = $this->guards->for($household);
        $recipes = [];
        $fingerprints = [];

        foreach (array_slice($candidates, 0, self::MAX_RECIPES) as $candidate) {
            $known = $inStock + $this->outOfStock($household, $candidate, $inStock);
            ['recipe' => $resolved, 'notices' => $notices] = $this->resolver->resolve($candidate, $known);

            $recipe = $this->normalizer->normalize($resolved);
            $recipe['stockConsumptionProposal'] = $this->merged($recipe['stockConsumptionProposal']);

            $labels = array_map(static fn (KnownFood $food): string => $food->label, $known);
            $safety = $this->safetyFilter->apply([$recipe], $guard, $labels);

            if ([] !== $safety['rejected']) {
                array_push($rejected, ...$safety['rejected']);
                continue;
            }

            // The same recipe pasted twice, or twice in one conversation, is one choice.
            $fingerprint = $this->normalizer->fingerprint($recipe);
            if (isset($fingerprints[$fingerprint])) {
                continue;
            }
            $fingerprints[$fingerprint] = true;

            $recipes[] = [
                'recipe' => $recipe,
                'plan' => $this->planner->plan($guard, $household->getStrictExclusionLabels(), $household, $recipe),
                'notices' => $notices,
            ];
        }

        return ['recipes' => $recipes, 'rejected' => $rejected];
    }

    /**
     * The household's foods the recipe names by key although none is in stock
     * today: eaten since the export, they are still the same food.
     *
     * @param array<string, mixed>     $recipe
     * @param array<string, KnownFood> $inStock
     *
     * @return array<string, KnownFood>
     */
    private function outOfStock(Household $household, array $recipe, array $inStock): array
    {
        $foods = [];

        foreach (['ingredientsFromInventory', 'stockConsumptionProposal'] as $field) {
            foreach (is_array($recipe[$field] ?? null) ? $recipe[$field] : [] as $entry) {
                $key = is_array($entry) && is_string($entry['key'] ?? null) ? trim($entry['key']) : '';

                if ('' === $key || isset($inStock[$key]) || isset($foods[$key])) {
                    continue;
                }

                $variant = $this->variants->findOneBySlug($household, $key);

                if (null !== $variant) {
                    $foods[$key] = new KnownFood($key, $variant->getCulinaryLabel(), $variant->getUnitFamily(), false);
                }
            }
        }

        return $foods;
    }

    /**
     * One line per food. Two lines for the same key would show twice on the
     * recipe screen and be confirmed as one; the resolver already made sure
     * their units belong to the food's family, so they always add up.
     *
     * @param list<array<string, mixed>> $proposal
     *
     * @return list<array{key: string, label: string, quantity: float, unit: string}>
     */
    private function merged(array $proposal): array
    {
        if ([] === $proposal) {
            return [];
        }

        return array_map(
            static fn (ConsumptionLine $line): array => [
                'key' => $line->key,
                'label' => $line->label,
                'quantity' => $line->quantity,
                'unit' => $line->unit->value,
            ],
            $this->consolidator->consolidate($proposal),
        );
    }

    /** @param list<array{recipeName: string, truncated: bool}> $unreadable */
    private static function nothingToImport(array $unreadable): string
    {
        if ([] === $unreadable) {
            return 'Aucune recette à importer dans ce texte. Copiez la réponse de l\'assistant avec le bloc '
                .'d\'import (le bloc de code JSON) qui termine chaque recette, ou demandez-lui : « Redonne le '
                .'bloc d\'import complet de la recette. »';
        }

        $first = $unreadable[0];

        return $first['truncated']
            ? sprintf('Le bloc d\'import de « %s » est coupé : copiez-le en entier, jusqu\'à son accolade finale.', $first['recipeName'])
            : sprintf('Le bloc d\'import de « %1$s » est illisible. Demandez à l\'assistant : « Redonne le bloc '
                .'d\'import complet de la recette %1$s, en JSON strict. »', $first['recipeName']);
    }
}
