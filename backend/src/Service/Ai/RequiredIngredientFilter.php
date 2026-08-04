<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Enum\Unit;
use App\Service\UnitConverter;

/**
 * Écarte les recettes qui n'utilisent pas l'aliment imposé, ou qui n'en
 * écoulent pas ce qui était demandé.
 *
 * Quand l'utilisateur désigne un produit à écouler — une botte de radis, un
 * reste de crème — la consigne au modèle ne suffit pas : il en propose deux sur
 * trois qui l'utilisent, et la troisième arrive quand même. Une contrainte qui
 * n'est vraie que la plupart du temps n'est pas une contrainte (leçon n° 14).
 *
 * Le critère est la **clé** de la variante, jamais son libellé : le modèle
 * recopie les clés de l'inventaire, et deux libellés proches — « Tomates » et
 * « Tomates (en conserve) » — désignent des stocks différents.
 *
 * Quand une quantité est imposée, un second critère s'ajoute au premier : ce
 * que la recette propose de déduire doit approcher la cible. Deux précautions
 * l'encadrent, et elles disent la même chose — un contrôle qui écarte les
 * recettes conformes finit par être désactivé :
 *
 * - une tolérance, parce qu'une recette pour deux ne tombe jamais au gramme
 *   près sur une cible choisie par un humain ;
 * - le silence quand la quantité n'est pas mesurable, parce que ne pas savoir
 *   mesurer n'est pas une preuve d'échec.
 */
class RequiredIngredientFilter
{
    /**
     * La part de la cible en dessous de laquelle une recette est écartée.
     *
     * 80 % : assez haut pour qu'une garniture de 100 g ne passe pas pour un
     * écoulement de 400 g, assez bas pour qu'une recette qui vise juste ne soit
     * pas punie d'avoir arrondi ses portions.
     */
    private const MINIMUM_RATIO = 0.8;

    public function __construct(private readonly UnitConverter $converter)
    {
    }

    /**
     * @param list<array<string, mixed>> $recipes
     *
     * @return array{recipes: list<array<string, mixed>>, rejected: list<array{recipeName: string, reason: string}>}
     */
    public function apply(array $recipes, ?ResolvedPriority $required): array
    {
        if (null === $required) {
            return ['recipes' => array_values($recipes), 'rejected' => []];
        }

        $kept = [];
        $rejected = [];

        foreach ($recipes as $recipe) {
            $reason = $this->rejectionReason($recipe, $required);

            if (null === $reason) {
                $kept[] = $recipe;
                continue;
            }

            $rejected[] = [
                'recipeName' => is_string($recipe['recipeName'] ?? null)
                    ? $recipe['recipeName']
                    : 'Proposition sans nom',
                'reason' => $reason,
            ];
        }

        return ['recipes' => $kept, 'rejected' => $rejected];
    }

    /**
     * Le motif d'écartement, ou `null` quand la recette satisfait la contrainte.
     *
     * @param array<string, mixed> $recipe
     */
    private function rejectionReason(array $recipe, ResolvedPriority $required): ?string
    {
        $line = $required->line;

        if (!$this->uses($recipe, $line->key)) {
            return sprintf('n\'utilise pas %s', $line->label);
        }

        if (!$required->hasTarget()) {
            return null;
        }

        $consumed = $this->consumed($recipe, $line->key, $required->unit);

        // Rien de mesurable : le modèle a écrit une unité d'une autre famille,
        // ou n'a rien déduit du tout. On s'en tient au contrôle de la clé.
        if (null === $consumed) {
            return null;
        }

        if ($consumed >= $required->quantity * self::MINIMUM_RATIO) {
            return null;
        }

        return sprintf(
            'n\'écoule que %s de %s sur les %s demandés',
            ResolvedPriority::format($consumed, $required->unit),
            $line->label,
            ResolvedPriority::format($required->quantity, $required->unit),
        );
    }

    /**
     * Deux endroits font foi, et pour deux raisons différentes : la proposition
     * de déduction dit ce qui sortira réellement du stock, la liste des
     * ingrédients dit ce que la recette met dans l'assiette. Un aliment présent
     * dans l'une sans l'autre est tout de même employé.
     *
     * @param array<string, mixed> $recipe
     */
    private function uses(array $recipe, string $key): bool
    {
        foreach (['stockConsumptionProposal', 'ingredientsFromInventory'] as $field) {
            $entries = $recipe[$field] ?? null;

            if (!is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (is_array($entry) && ($entry['key'] ?? null) === $key) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Ce que la recette propose de retirer du stock pour cette clé, dans
     * `$unit`.
     *
     * Seule `stockConsumptionProposal` est lue, et c'est le propos : « écouler »
     * parle de ce qui quitte le placard. Un aliment nommé dans la recette sans
     * jamais être déduit satisfait le contrôle de la clé — il est bien employé —
     * mais n'écoule rien, et **zéro est une mesure** : la recette est écartée.
     *
     * `null` est réservé au cas où l'on ne sait pas lire ce qui est pourtant
     * écrit — une déduction en portions contre une cible en grammes. Ne pas
     * savoir mesurer et mesurer zéro sont deux situations opposées : la première
     * appelle le silence, la seconde le rejet. Les confondre laisserait passer
     * exactement les recettes que la quantité imposée devait écarter.
     *
     * @param array<string, mixed> $recipe
     */
    private function consumed(array $recipe, string $key, Unit $unit): ?float
    {
        $entries = is_array($recipe['stockConsumptionProposal'] ?? null)
            ? $recipe['stockConsumptionProposal']
            : [];

        $total = 0.0;
        $mentioned = false;
        $measured = false;

        foreach ($entries as $entry) {
            if (!is_array($entry) || ($entry['key'] ?? null) !== $key) {
                continue;
            }

            $mentioned = true;
            $quantity = $entry['quantity'] ?? null;
            $entryUnit = is_string($entry['unit'] ?? null) ? Unit::tryFrom($entry['unit']) : null;

            if (!is_numeric($quantity) || null === $entryUnit || !$this->converter->canConvert($entryUnit, $unit)) {
                continue;
            }

            $measured = true;
            $total += $this->converter->convert((float) $quantity, $entryUnit, $unit);
        }

        return $mentioned && !$measured ? null : $total;
    }
}
