<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Réduit « Pâtes Penne Rigate Barilla — 500g » à « pate-penne-rigate ».
 *
 * Cette clé fait le lien entre ce que l'IA renvoie (« pâtes ») et les lots
 * réellement en stock. Sans normalisation, « Tomates » et « tomate » seraient
 * deux ingrédients différents et la déduction de stock échouerait.
 */
class IngredientKeyGenerator
{
    /**
     * Mots vides et mentions marketing qui n'identifient pas l'aliment.
     *
     * « fraiche » en est volontairement absent : « crème fraîche » n'est pas
     * « crème ». On ne retire que ce qui ne change pas la nature de l'aliment.
     */
    private const NOISE = [
        'de', 'du', 'des', 'la', 'le', 'les', 'un', 'une', 'au', 'aux', 'a', 'et', 'en',
        'bio', 'nature', 'surgele', 'surgelee', 'nouveau',
    ];

    /** Quantités et conditionnements collés au nom commercial. */
    private const PACKAGING_PATTERN = '/\b\d+[\.,]?\d*\s*(g|kg|ml|cl|l|litres?|grammes?|pieces?|x)\b/iu';

    public function __construct(private readonly TextNormalizer $normalizer)
    {
    }

    public function generate(string $rawName): string
    {
        $value = $this->normalizer->toAscii($rawName);
        $value = preg_replace(self::PACKAGING_PATTERN, ' ', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        $words = [];
        foreach (preg_split('/\s+/', trim($value)) ?: [] as $word) {
            // Les mots d'une lettre sont des articles élidés (« huile d'olive »).
            if (mb_strlen($word) <= 1 || in_array($word, self::NOISE, true) || is_numeric($word)) {
                continue;
            }
            $words[] = $this->singularize($word);
        }

        // Au-delà de trois mots on décrit la marque, plus l'aliment.
        $words = array_slice($words, 0, 3);

        return '' === implode('', $words) ? 'inconnu' : implode('-', $words);
    }

    /**
     * Singularisation volontairement naïve : elle ne doit jamais transformer
     * « riz » en « ri » ni « ananas » en « anana ».
     */
    private function singularize(string $word): string
    {
        if (mb_strlen($word) <= 3) {
            return $word;
        }

        foreach (['aux' => 'al', 'eaux' => 'eau'] as $suffix => $replacement) {
            if (str_ends_with($word, $suffix)) {
                return substr($word, 0, -mb_strlen($suffix)).$replacement;
            }
        }

        if (str_ends_with($word, 'ss') || str_ends_with($word, 'us') || str_ends_with($word, 'as')) {
            return $word;
        }

        return str_ends_with($word, 's') || str_ends_with($word, 'x')
            ? substr($word, 0, -1)
            : $word;
    }
}
