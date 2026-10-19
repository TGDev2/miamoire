<?php

declare(strict_types=1);

namespace App\Service\Ai\Import;

/**
 * Finds the recipe blocks in a text pasted from another assistant.
 *
 * The text is whatever a chat lets the user copy: prose, Markdown, fenced or
 * unfenced JSON, the prompt itself sometimes, and a block cut short when the
 * selection stopped too early. Rather than parsing Markdown, the extractor
 * scans for balanced brackets and lets `json_decode` judge each candidate:
 *
 * - a candidate that decodes is consumed whole — nothing inside it is looked at
 *   again, which keeps the prompt's data from being read as recipes;
 * - one that does not (prose, a stray brace, a cut block) is skipped by a
 *   single byte, so a lone `{` in a sentence cannot swallow the blocks after it.
 *
 * Nothing is repaired. A block with a trailing comma or curly quotes is
 * reported by name as unreadable: guessing would import a recipe nobody wrote.
 */
final class RecipeBlockExtractor
{
    /** Spaces that French typography and chat apps insert where JSON only accepts plain ones. */
    private const ODD_SPACES = ["\u{00A0}", "\u{202F}", "\u{FEFF}", "\u{200B}"];

    /**
     * How many bytes the scan may read in total. A stray bracket costs one pass
     * to the end of the text; a text made of thousands of them would otherwise
     * cost thousands of passes.
     */
    private const SCAN_BUDGET = 5_000_000;

    /** The name of a recipe, read from text that `json_decode` refused. Bytes, not characters. */
    private const NAME_PATTERN = '/"recipeName"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/';

    /**
     * @return array{
     *     recipes: list<array<string, mixed>>,
     *     unreadable: list<array{recipeName: string, truncated: bool}>,
     * }
     */
    public function extract(string $text): array
    {
        // Every replaced character is multibyte and none is a bracket or a quote:
        // byte offsets stay meaningful for what the scan looks for.
        $text = str_replace(self::ODD_SPACES, ' ', $text);
        $length = \strlen($text);

        $recipes = [];
        /** @var list<array{int, int}> $decoded */
        $decoded = [];
        /** @var list<array{int, ?int}> $failed */
        $failed = [];
        $budget = self::SCAN_BUDGET;
        $offset = 0;

        while ($budget > 0 && ($offset += strcspn($text, '{[', $offset)) < $length) {
            $end = self::closingIndex($text, $offset);
            $budget -= ($end ?? $length) - $offset + 1;

            if (null !== $end) {
                $value = json_decode(substr($text, $offset, $end - $offset + 1), true);

                if (is_array($value)) {
                    array_push($recipes, ...self::recipesIn($value));
                    $decoded[] = [$offset, $end];
                    $offset = $end + 1;
                    continue;
                }
            }

            $failed[] = [$offset, $end];
            ++$offset;
        }

        return ['recipes' => $recipes, 'unreadable' => self::unreadable($text, $decoded, $failed)];
    }

    /** The index of the bracket closing the one at `$start`, or `null` if the text ends first. */
    private static function closingIndex(string $text, int $start): ?int
    {
        $length = \strlen($text);
        $depth = 0;
        $inString = false;

        for ($i = $start; $i < $length; ++$i) {
            $char = $text[$i];

            if ($inString) {
                if ('\\' === $char) {
                    ++$i;
                } elseif ('"' === $char) {
                    $inString = false;
                }
                continue;
            }

            if ('"' === $char) {
                $inString = true;
            } elseif ('{' === $char || '[' === $char) {
                ++$depth;
            } elseif (('}' === $char || ']' === $char) && 0 === --$depth) {
                return $i;
            }
        }

        return null;
    }

    /**
     * One recipe, a list of recipes, or the `{"recipes": [...]}` wrapper that
     * the app's own schema uses.
     *
     * @param array<array-key, mixed> $value
     *
     * @return list<array<string, mixed>>
     */
    private static function recipesIn(array $value): array
    {
        if (is_array($value['recipes'] ?? null)) {
            $value = $value['recipes'];
        }

        $candidates = array_is_list($value) ? $value : [$value];

        return array_values(array_filter($candidates, self::isRecipe(...)));
    }

    private static function isRecipe(mixed $value): bool
    {
        if (!is_array($value) || array_is_list($value) || !is_string($value['recipeName'] ?? null)) {
            return false;
        }

        $name = trim($value['recipeName']);

        return '' !== $name && RecipeImportFormat::EXAMPLE_NAME !== $name;
    }

    /**
     * The recipes seen but not read: a `"recipeName"` that lies inside a
     * candidate `json_decode` refused, and inside nothing it accepted.
     *
     * `truncated` says whether that candidate ever closed: a block that runs to
     * the end of the text was cut by the copy, one that closes is malformed.
     * The user's next step differs — copy again, or ask the assistant again.
     *
     * @param list<array{int, int}>  $decoded
     * @param list<array{int, ?int}> $failed
     *
     * @return list<array{recipeName: string, truncated: bool}>
     */
    private static function unreadable(string $text, array $decoded, array $failed): array
    {
        if ([] === $failed || false === preg_match_all(self::NAME_PATTERN, $text, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $unreadable = [];

        foreach ($matches as $match) {
            $position = $match[0][1];

            foreach ($decoded as [$start, $end]) {
                if ($position > $start && $position < $end) {
                    continue 2;
                }
            }

            // The innermost refused candidate around the name.
            $enclosing = null;
            foreach ($failed as $candidate) {
                [$start, $end] = $candidate;
                if ($start < $position && (null === $end || $end > $position)) {
                    $enclosing = $candidate;
                }
            }

            if (null === $enclosing) {
                continue;
            }

            $raw = $match[1][0];
            $name = trim(is_string($unescaped = json_decode('"'.$raw.'"')) ? $unescaped : $raw);

            if ('' === $name || RecipeImportFormat::EXAMPLE_NAME === $name) {
                continue;
            }

            $unreadable[$name] = ['recipeName' => $name, 'truncated' => null === $enclosing[1]];
        }

        return array_values($unreadable);
    }
}
