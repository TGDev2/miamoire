<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Translittération commune : « Pâtes Pénne » devient « pates penne ».
 *
 * Partagée par IngredientKeyGenerator et ProductTraitDetector : deux
 * normalisations divergentes finiraient par ne plus reconnaître les mêmes
 * aliments, et le rapprochement recette ↔ stock échouerait silencieusement.
 */
class TextNormalizer
{
    /** Minuscules, sans accent, sans caractère non latin. */
    public function toAscii(string $value): string
    {
        if (class_exists(\Transliterator::class)) {
            $transliterator = \Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
            if (null !== $transliterator) {
                $converted = $transliterator->transliterate($value);
                if (false !== $converted) {
                    return $converted;
                }
            }
        }

        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);

        return mb_strtolower(false === $converted ? $value : $converted);
    }

    /** Ajoute la réduction de la ponctuation à des espaces : prêt pour une recherche par mot. */
    public function toWords(string $value): string
    {
        $ascii = $this->toAscii($value);

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? $ascii);
    }
}
