<?php

declare(strict_types=1);

namespace App\Service\Food;

use App\Service\TextNormalizer;

/**
 * Reconnaît un aliment dans un libellé de courses, et rend le terme le plus précis.
 *
 * Cette logique vivait dans `ShelfLifeEstimator`, seul endroit qui en avait besoin.
 * Le lexique du frais ({@see FreshFoodLexicon}) pose exactement la même question sur
 * exactement les mêmes libellés : la dupliquer, c'était garantir qu'un jour l'un
 * reconnaîtrait « pommes de terre » et l'autre « pommes ».
 *
 * Trois règles, toutes payées par un bug :
 *
 * - **le terme le plus long gagne** — « pomme de terre » n'est pas une « pomme »,
 *   et se conserve six fois plus longtemps ;
 * - **certains termes se cherchent au mot près** — « pâtes » et « pâté » se
 *   normalisent tous deux en `pate`, et une tolérance de pluriel donnerait un an
 *   de conservation à une terrine de campagne (leçon n° 2). À longueur égale, le
 *   terme exact l'emporte donc sur le terme tolérant ;
 * - **certains termes l'emportent quelle que soit leur longueur** — « soupe de
 *   potiron » désignait `potiron`, plus long, et la soupe héritait des soixante
 *   jours de placard d'une courge entière (voir {@see PreparedDish}).
 */
final class FoodTermMatcher
{
    public function __construct(private readonly TextNormalizer $normalizer)
    {
    }

    /**
     * @template T
     *
     * @param array<string, T> $tolerant    termes reconnus au pluriel près
     * @param array<string, T> $exact       termes reconnus au mot près, sans tolérance
     * @param list<string>     $overriding  termes qui l'emportent quelle que soit leur
     *                                      longueur ; ceux qui n'appartiennent à aucune
     *                                      des deux tables sont simplement ignorés
     *
     * @return array{term: string, value: T}|null
     */
    public function match(string $label, array $tolerant, array $exact = [], array $overriding = []): ?array
    {
        $haystack = $this->normalizer->toWords($label);

        $best = null;
        $bestOverriding = null;

        // Les termes exacts d'abord : à longueur égale, ce sont eux qui restent.
        foreach ($exact + $tolerant as $term => $value) {
            $term = (string) $term;

            $pattern = isset($exact[$term])
                ? '/\b'.preg_quote($term, '/').'\b/'
                : $this->pluralTolerantPattern($term);

            if (1 !== preg_match($pattern, $haystack)) {
                continue;
            }

            if (in_array($term, $overriding, true)) {
                $bestOverriding = $this->keepLongest($bestOverriding, $term, $value);

                continue;
            }

            $best = $this->keepLongest($best, $term, $value);
        }

        return $bestOverriding ?? $best;
    }

    /**
     * @template T
     *
     * @param array{term: string, value: T}|null $best
     * @param T                                  $value
     *
     * @return array{term: string, value: T}
     */
    private function keepLongest(?array $best, string $term, mixed $value): array
    {
        return null === $best || mb_strlen($term) > mb_strlen($best['term'])
            ? ['term' => $term, 'value' => $value]
            : $best;
    }

    /** « pomme de terre » doit reconnaître « pommes de terre » sans reconnaître « pommade ». */
    private function pluralTolerantPattern(string $term): string
    {
        $words = array_map(
            static fn (string $word): string => mb_strlen($word) > 3
                ? preg_quote($word, '/').'(?:s|x)?'
                : preg_quote($word, '/'),
            explode(' ', $term),
        );

        return '/\b'.implode('\s+', $words).'\b/';
    }
}
