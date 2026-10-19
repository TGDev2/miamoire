<?php

declare(strict_types=1);

namespace App\Service\Ai\Safety;

use App\Enum\Allergen;
use App\Service\TextNormalizer;

/**
 * Le contrôle qui rend une exclusion alimentaire opposable.
 *
 * Jusqu'ici, « ne propose aucune recette avec des arachides » n'était qu'une phrase
 * dans la consigne envoyée au modèle. Rien ne le forçait à la respecter, et rien
 * ne permettait de vérifier qu'il l'avait fait. Une allergie ne se demande pas
 * poliment : elle se vérifie.
 *
 * Le garde travaille en deux temps, et l'ordre compte :
 *
 * 1. il **efface** du texte les expressions qui contiennent un terme sans être
 *    l'allergène — « lait de coco », « farine de riz », « beurre de cacahuète »
 *    quand c'est le lait qu'on évite ;
 * 2. il **cherche** ensuite les termes restants, sur frontière de mot, pour que
 *    « vin » ne se déclenche pas sur « vinaigre » ni « ble » sur « ensemble ».
 *
 * Sans la première étape le contrôle serait inutilisable ; sans la seconde il
 * serait inutile.
 */
final class DietaryGuard
{
    /** @var list<array{exclusion: string, patterns: list<string>, exceptions: list<string>}> */
    private array $rules;

    /** @param list<string> $exclusions texte libre saisi par le foyer */
    public function __construct(
        private readonly TextNormalizer $normalizer,
        array $exclusions,
    ) {
        $this->rules = $this->compile($exclusions);
    }

    public function isEmpty(): bool
    {
        return [] === $this->rules;
    }

    /** @return list<string> */
    public function exclusions(): array
    {
        return array_column($this->rules, 'exclusion');
    }

    /** Le premier manquement trouvé dans ce texte, ou `null` s'il est conforme. */
    public function inspect(string $text, string $location): ?DietaryViolation
    {
        if ([] === $this->rules || '' === trim($text)) {
            return null;
        }

        $haystack = $this->pad($this->normalizer->toWords($text));

        foreach ($this->rules as $rule) {
            $cleaned = $haystack;

            // L'ordre est essentiel : on retire d'abord les faux positifs connus.
            foreach ($rule['exceptions'] as $exception) {
                $cleaned = preg_replace($exception, ' ', $cleaned) ?? $cleaned;
            }

            foreach ($rule['patterns'] as $pattern) {
                if (1 === preg_match($pattern, $cleaned, $found)) {
                    return new DietaryViolation($rule['exclusion'], trim($found[0]), $location);
                }
            }
        }

        return null;
    }

    /**
     * A list field is numbered by line, as the user reads it. A line may carry
     * several texts — what the recipe wrote and the stock's real label — each
     * judged on its own, so that one text's exception (« sans gluten ») never
     * excuses another's match.
     *
     * @param array<string, string|list<string|list<string>>> $fields texte indexé par emplacement lisible
     */
    public function inspectFields(array $fields): ?DietaryViolation
    {
        foreach ($fields as $location => $value) {
            foreach (is_array($value) ? $value : [$value] as $index => $line) {
                $where = is_array($value) ? sprintf('%s %d', $location, $index + 1) : $location;

                foreach (is_array($line) ? $line : [$line] as $text) {
                    if (!is_string($text)) {
                        continue;
                    }

                    $violation = $this->inspect($text, $where);

                    if (null !== $violation) {
                        return $violation;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $exclusions
     *
     * @return list<array{exclusion: string, patterns: list<string>, exceptions: list<string>}>
     */
    private function compile(array $exclusions): array
    {
        $rules = [];
        $seen = [];

        foreach ($exclusions as $raw) {
            $label = trim($raw);
            if ('' === $label) {
                continue;
            }

            $normalized = $this->normalizer->toWords($label);
            if ('' === $normalized || isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;

            $allergen = Allergen::fromExclusionText($normalized);

            $rules[] = null !== $allergen
                ? $this->allergenRule($label, $allergen)
                : $this->freeTextRule($label, $normalized);
        }

        return $rules;
    }

    /**
     * @return array{exclusion: string, patterns: list<string>, exceptions: list<string>}
     */
    private function allergenRule(string $label, Allergen $allergen): array
    {
        $patterns = array_merge(
            array_map(fn (string $term) => $this->tolerantPattern($term), $allergen->terms()),
            array_map(fn (string $term) => $this->strictPattern($term), $allergen->strictTerms()),
        );

        return [
            'exclusion' => $allergen->label(),
            'patterns' => $patterns,
            'exceptions' => $this->exceptionPatterns(
                $allergen->exceptions(),
                array_merge($allergen->terms(), $allergen->strictTerms()),
            ),
        ];
    }

    /**
     * Une exclusion que le catalogue ne connaît pas — « brocoli », « coriandre ».
     *
     * Aucun dérivé n'est deviné : on ne cherche que ce qui a été écrit. Inventer
     * des synonymes pour un terme inconnu produirait des rejets incompréhensibles.
     *
     * @return array{exclusion: string, patterns: list<string>, exceptions: list<string>}
     */
    private function freeTextRule(string $label, string $normalized): array
    {
        return [
            'exclusion' => $label,
            'patterns' => [$this->tolerantPattern($normalized)],
            'exceptions' => $this->exceptionPatterns([], [$normalized]),
        ];
    }

    /**
     * « sans X » n'est jamais X — la règle vaut pour tous les termes, y compris
     * ceux saisis en texte libre : une recette « sans gluten » ne contient pas de
     * gluten, et un plat « sans arachide » se laisse manger.
     *
     * @param list<string> $phrases
     * @param list<string> $terms
     *
     * @return list<string>
     */
    private function exceptionPatterns(array $phrases, array $terms): array
    {
        $patterns = array_map(fn (string $phrase) => $this->tolerantPattern($phrase), $phrases);

        foreach ($terms as $term) {
            $patterns[] = $this->freeFromPattern($term);
        }

        return $patterns;
    }

    /**
     * « sans gluten » et l'aliment qu'il qualifie.
     *
     * Retirer la seule mention « sans gluten » ne suffit pas : « pain sans gluten »
     * laisserait le mot « pain », qui est justement un terme du gluten. On absorbe
     * donc le mot qui précède — un seul, délibérément, pour que
     * « pain sans gluten puis de la farine de blé » continue de se faire écarter
     * sur la farine.
     */
    private function freeFromPattern(string $term): string
    {
        return '/\b(?:[a-z0-9]+\s+)?sans\s+'.$this->tolerantBody($term).'\b/';
    }

    /** Tolère le pluriel : « cacahuete » reconnaît « cacahuetes ». */
    private function tolerantPattern(string $term): string
    {
        return '/\b'.$this->tolerantBody($term).'\b/';
    }

    private function tolerantBody(string $term): string
    {
        $words = array_map(
            static fn (string $word): string => mb_strlen($word) > 3
                ? preg_quote($word, '/').'(?:s|x)?'
                : preg_quote($word, '/'),
            array_filter(explode(' ', $term), static fn (string $word) => '' !== $word),
        );

        return implode('\s+', $words);
    }

    private function strictPattern(string $term): string
    {
        return '/\b'.preg_quote($term, '/').'\b/';
    }

    /** Des espaces aux extrémités : `\b` reste vrai en début et en fin de texte. */
    private function pad(string $value): string
    {
        return ' '.$value.' ';
    }
}
