<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Enum\FoodGroup;
use App\Service\Food\FoodGroupLexicon;
use App\Service\TextNormalizer;

/**
 * Écarte les recettes qui ne contiennent pas une composante demandée — un
 * féculent, un légume, une protéine.
 *
 * Comme pour l'aliment imposé, la consigne au modèle ne suffit pas : une
 * contrainte respectée la plupart du temps n'en est pas une (leçon n° 14).
 *
 * Le critère est la déclaration `foodGroups` de la recette : aucune donnée du
 * stock ne range un aliment dans un groupe, le classement part donc du modèle.
 * Mais une déclaration n'est pas crue sur parole. Sans aucune protéine en stock,
 * le modèle a déclaré « Riz basmati » comme protéine dans les trois recettes
 * plutôt que d'avouer le manque. Le {@see FoodGroupLexicon} dément ces
 * déclarations pour les aliments courants ; pour un aliment qu'il ne connaît
 * pas, le modèle reste seul juge.
 *
 * Un groupe fourni par un ingrédient facultatif ne compte pas non plus : une
 * purée-poulet a passé la demande « légume » grâce à « Carottes (facultatif) »,
 * qu'on peut ne jamais mettre dans l'assiette.
 */
class FoodGroupFilter
{
    public function __construct(
        private readonly FoodGroupLexicon $lexicon,
        private readonly TextNormalizer $normalizer,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $recipes
     * @param list<FoodGroup>            $groups
     *
     * @return array{recipes: list<array<string, mixed>>, rejected: list<array{recipeName: string, reason: string}>}
     */
    public function apply(array $recipes, array $groups): array
    {
        if ([] === $groups) {
            return ['recipes' => array_values($recipes), 'rejected' => []];
        }

        $kept = [];
        $rejected = [];

        foreach ($recipes as $recipe) {
            $missing = array_values(array_filter(
                $groups,
                fn (FoodGroup $group): bool => !$this->declares($recipe, $group),
            ));

            if ([] === $missing) {
                $kept[] = $recipe;
                continue;
            }

            $rejected[] = [
                'recipeName' => is_string($recipe['recipeName'] ?? null)
                    ? $recipe['recipeName']
                    : 'Proposition sans nom',
                'reason' => 'sans '.implode(' ni ', array_map(
                    static fn (FoodGroup $group): string => mb_strtolower($group->label()),
                    $missing,
                )),
            ];
        }

        return ['recipes' => $kept, 'rejected' => $rejected];
    }

    /**
     * Un groupe ne compte que nommé avec l'ingrédient qui le fournit — une
     * entrée sans ingrédient est une case cochée, pas un engagement — quand le
     * lexique ne range pas cet ingrédient ailleurs, et quand la recette ne le
     * marque pas facultatif.
     *
     * @param array<string, mixed> $recipe
     */
    private function declares(array $recipe, FoodGroup $group): bool
    {
        $entries = is_array($recipe['foodGroups'] ?? null) ? $recipe['foodGroups'] : [];

        foreach ($entries as $entry) {
            if (
                !is_array($entry)
                || ($entry['group'] ?? null) !== $group->value
                || !is_string($entry['ingredient'] ?? null)
                || '' === trim($entry['ingredient'])
            ) {
                continue;
            }

            $known = $this->lexicon->groupsOf($entry['ingredient']);

            if (null !== $known && !in_array($group, $known, true)) {
                continue;
            }

            if (!$this->isOptional($recipe, $entry['ingredient'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * L'ingrédient figure-t-il dans la recette avec `essential: false` ?
     *
     * Retrouvé par son libellé normalisé. Un libellé introuvable n'est pas
     * facultatif : ne pas savoir le relier n'est pas une preuve contre lui, et
     * l'écarter pour cela punirait une recette qui a seulement reformulé.
     *
     * @param array<string, mixed> $recipe
     */
    private function isOptional(array $recipe, string $ingredient): bool
    {
        $wanted = $this->normalizer->toWords($ingredient);

        foreach (['ingredientsFromInventory', 'missingIngredients'] as $field) {
            $entries = is_array($recipe[$field] ?? null) ? $recipe[$field] : [];

            foreach ($entries as $entry) {
                if (
                    is_array($entry)
                    && is_string($entry['label'] ?? null)
                    && $this->normalizer->toWords($entry['label']) === $wanted
                ) {
                    return false === ($entry['essential'] ?? null);
                }
            }
        }

        return false;
    }
}
