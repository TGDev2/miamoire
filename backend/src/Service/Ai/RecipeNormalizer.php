<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Enum\Unit;
use App\Exception\StockException;

/**
 * Ramène une recette reçue de l'application à la forme exacte qu'on accepte de
 * conserver, et lui donne une empreinte stable.
 *
 * Deux raisons, et la seconde est la vraie.
 *
 * 1. Une recette enregistrée est du JSON venu du client : sans liste blanche,
 *    n'importe quel document finirait en base sous couvert d'« enregistrer une
 *    recette », et serait renvoyé tel quel à tous les membres du foyer.
 * 2. Une recette relue six mois plus tard doit encore pouvoir déduire du stock.
 *    Les champs qui pilotent cette déduction — `key`, `quantity`, `unit` — sont
 *    donc validés à l'entrée, pas au moment où l'utilisateur appuie sur
 *    « retirer du stock » et découvre que sa recette est inutilisable.
 */
class RecipeNormalizer
{
    /** Une recette d'une taille déraisonnable ne vient pas de l'écran de propositions. */
    private const MAX_STEPS = 40;
    private const MAX_INGREDIENTS = 60;

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    public function normalize(array $raw): array
    {
        $name = is_string($raw['recipeName'] ?? null) ? trim($raw['recipeName']) : '';

        if ('' === $name) {
            throw new StockException('Une recette enregistrée doit porter un nom.');
        }

        $feasibility = is_string($raw['feasibility'] ?? null) ? $raw['feasibility'] : RecipeSchema::FEASIBILITY_READY;

        return [
            'recipeName' => mb_substr($name, 0, 200),
            'feasibility' => in_array($feasibility, self::feasibilities(), true)
                ? $feasibility
                : RecipeSchema::FEASIBILITY_READY,
            'servings' => max(1, (int) ($raw['servings'] ?? 1)),
            'preparationTime' => max(0, (int) ($raw['preparationTime'] ?? 0)),
            'summary' => is_string($raw['summary'] ?? null) ? mb_substr(trim($raw['summary']), 0, 1000) : '',
            'ingredientsFromInventory' => $this->ingredients($raw['ingredientsFromInventory'] ?? null, true),
            'missingIngredients' => $this->ingredients($raw['missingIngredients'] ?? null, false),
            'substitutions' => $this->substitutions($raw['substitutions'] ?? null),
            'steps' => $this->steps($raw['steps'] ?? null),
            'stockConsumptionProposal' => $this->proposal($raw['stockConsumptionProposal'] ?? null),
        ];
    }

    /**
     * Empreinte d'une recette normalisée.
     *
     * Elle sert d'index unique par foyer : appuyer deux fois sur « Enregistrer »,
     * ou revenir sur la même proposition le lendemain, ne crée pas de doublon.
     * Le tri récursif des clés la rend indépendante de l'ordre dans lequel le
     * client a sérialisé son objet — deux JSON équivalents donnent la même valeur.
     *
     * @param array<string, mixed> $recipe
     */
    public function fingerprint(array $recipe): string
    {
        $sorted = $this->sortDeep($recipe);

        return hash('sha256', json_encode($sorted, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    /** @return list<string> */
    private static function feasibilities(): array
    {
        return [
            RecipeSchema::FEASIBILITY_READY,
            RecipeSchema::FEASIBILITY_ADAPTED,
            RecipeSchema::FEASIBILITY_NEEDS_SHOPPING,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ingredients(mixed $entries, bool $keyed): array
    {
        if (!is_array($entries)) {
            return [];
        }

        $lines = [];

        foreach (array_slice($entries, 0, self::MAX_INGREDIENTS) as $entry) {
            if (!is_array($entry) || !is_string($entry['label'] ?? null)) {
                continue;
            }

            $line = [
                'label' => mb_substr(trim($entry['label']), 0, 200),
                'quantity' => is_numeric($entry['quantity'] ?? null) ? round((float) $entry['quantity'], 3) : 0.0,
                'unit' => (Unit::tryFrom(is_string($entry['unit'] ?? null) ? $entry['unit'] : '') ?? Unit::Piece)->value,
                'essential' => (bool) ($entry['essential'] ?? true),
            ];

            // Seuls les ingrédients venus de l'inventaire portent une clé ; ceux
            // à acheter n'en ont pas, par construction.
            if ($keyed && is_string($entry['key'] ?? null) && '' !== trim($entry['key'])) {
                $line['key'] = trim($entry['key']);
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @return list<array{key: string, label: string, quantity: float, unit: string}>
     */
    private function proposal(mixed $entries): array
    {
        if (!is_array($entries)) {
            return [];
        }

        $lines = [];

        foreach (array_slice($entries, 0, self::MAX_INGREDIENTS) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $key = is_string($entry['key'] ?? null) ? trim($entry['key']) : '';
            $unit = Unit::tryFrom(is_string($entry['unit'] ?? null) ? $entry['unit'] : '');
            $quantity = is_numeric($entry['quantity'] ?? null) ? (float) $entry['quantity'] : 0.0;

            // Une ligne sans clé, sans unité connue ou sans quantité ne pourra
            // jamais déduire quoi que ce soit : la garder ferait échouer la
            // confirmation des mois plus tard, sans que rien ne l'ait annoncé.
            if ('' === $key || null === $unit || $quantity <= 0) {
                continue;
            }

            $lines[] = [
                'key' => $key,
                'label' => is_string($entry['label'] ?? null) && '' !== trim($entry['label'])
                    ? mb_substr(trim($entry['label']), 0, 200)
                    : $key,
                'quantity' => round($quantity, 3),
                'unit' => $unit->value,
            ];
        }

        return $lines;
    }

    /**
     * @return list<array{original: string, replacedBy: string, reason: string}>
     */
    private function substitutions(mixed $entries): array
    {
        if (!is_array($entries)) {
            return [];
        }

        $lines = [];

        foreach (array_slice($entries, 0, self::MAX_INGREDIENTS) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $lines[] = [
                'original' => is_string($entry['original'] ?? null) ? mb_substr(trim($entry['original']), 0, 200) : '',
                'replacedBy' => is_string($entry['replacedBy'] ?? null) ? mb_substr(trim($entry['replacedBy']), 0, 200) : '',
                'reason' => is_string($entry['reason'] ?? null) ? mb_substr(trim($entry['reason']), 0, 500) : '',
            ];
        }

        return $lines;
    }

    /** @return list<string> */
    private function steps(mixed $steps): array
    {
        if (!is_array($steps)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($step) => is_string($step) ? mb_substr(trim($step), 0, 1000) : null,
            array_slice($steps, 0, self::MAX_STEPS),
        )));
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private function sortDeep(array $value): array
    {
        ksort($value);

        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->sortDeep($entry);
            }
        }

        return $value;
    }
}
