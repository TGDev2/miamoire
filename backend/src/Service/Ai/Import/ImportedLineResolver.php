<?php

declare(strict_types=1);

namespace App\Service\Ai\Import;

use App\Enum\Unit;
use App\Enum\UnitFamily;
use App\Service\TextNormalizer;

/**
 * Ties the lines of an imported recipe to the household's foods.
 *
 * The app deducts by key (the variant slug); another assistant writes what it
 * understood, and sometimes only a label. A line is tied when that is certain:
 *
 * - a key that names a household food is kept — unless its label names, word
 *   for word, a *different* food in stock: then which one was eaten is unknown;
 * - without a usable key, the label must equal one in-stock food's culinary
 *   label, and only one. "Tomates" never falls back on "Tomates (en conserve)":
 *   the culinary label carries the state precisely so the two stay apart.
 *
 * A deduction line that cannot be tied, or whose unit cannot apply to the food,
 * is dropped and said. Kept, it would make "Retirer du stock" fail for the whole
 * recipe the day it is cooked (`RecipeConfirmationService::resolveTargets`).
 * An ingredient line only loses its link to the lot: it stays, silently.
 */
final class ImportedLineResolver
{
    public function __construct(private readonly TextNormalizer $normalizer)
    {
    }

    /**
     * @param array<string, mixed>     $recipe a recipe as decoded from its block, before normalization
     * @param array<string, KnownFood> $known  by key: every food in stock, and any other one the recipe names by key
     *
     * @return array{recipe: array<string, mixed>, notices: list<string>}
     */
    public function resolve(array $recipe, array $known): array
    {
        /** @var array<string, list<KnownFood>> $inStockByLabel */
        $inStockByLabel = [];
        foreach ($known as $food) {
            if ($food->inStock) {
                $inStockByLabel[$this->normalizer->toWords($food->label)][] = $food;
            }
        }

        $notices = [];

        if (is_array($recipe['ingredientsFromInventory'] ?? null)) {
            $lines = [];
            foreach ($recipe['ingredientsFromInventory'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                [$food] = $this->food($entry, $known, $inStockByLabel);

                if (null === $food) {
                    unset($entry['key']);
                } else {
                    $entry['key'] = $food->key;
                }

                $lines[] = $entry;
            }
            $recipe['ingredientsFromInventory'] = $lines;
        }

        if (is_array($recipe['stockConsumptionProposal'] ?? null)) {
            $lines = [];
            foreach ($recipe['stockConsumptionProposal'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                [$line, $notice] = $this->deduction($entry, $known, $inStockByLabel);

                if (null !== $line) {
                    $lines[] = $line;
                }
                if (null !== $notice) {
                    $notices[] = $notice;
                }
            }
            $recipe['stockConsumptionProposal'] = $lines;
        }

        return ['recipe' => $recipe, 'notices' => $notices];
    }

    /**
     * @param array<array-key, mixed>        $entry
     * @param array<string, KnownFood>       $known
     * @param array<string, list<KnownFood>> $inStockByLabel
     *
     * @return array{?array{key: string, label: string, quantity: float, unit: string}, ?string}
     */
    private function deduction(array $entry, array $known, array $inStockByLabel): array
    {
        $label = self::labelOf($entry);
        [$food, $contradicted] = $this->food($entry, $known, $inStockByLabel);

        if (null !== $contradicted) {
            return [null, sprintf('« %s » : sa clé désigne « %s », un autre aliment de votre stock. Il ne sera pas retiré.', $label, $contradicted->label)];
        }

        if (null === $food) {
            return [null, sprintf('« %s » n\'a pas été reconnu dans votre stock : il ne sera pas retiré.', $label)];
        }

        $written = is_string($entry['unit'] ?? null) ? trim($entry['unit']) : '';
        $unit = Unit::tryFrom($written);

        if (null === $unit) {
            return [null, '' === $written
                ? sprintf('« %s » : unité manquante, il ne sera pas retiré.', $label)
                : sprintf('« %s » : l\'unité « %s » n\'est pas reconnue, il ne sera pas retiré.', $label, $written)];
        }

        $quantity = $entry['quantity'] ?? null;

        if (!is_numeric($quantity) || (float) $quantity <= 0) {
            return [null, sprintf('« %s » : quantité illisible, il ne sera pas retiré.', $label)];
        }

        if ($unit->family() !== $food->family) {
            return [null, sprintf(
                '« %s » se compte %s dans votre stock, pas en %s : il ne sera pas retiré.',
                $food->label,
                self::countedAs($food->family),
                $unit->label(),
            )];
        }

        return [['key' => $food->key, 'label' => $food->label, 'quantity' => (float) $quantity, 'unit' => $unit->value], null];
    }

    /**
     * The food a line names, or, when its key and its label name two different
     * foods, `null` and the food the key named.
     *
     * @param array<array-key, mixed>        $entry
     * @param array<string, KnownFood>       $known
     * @param array<string, list<KnownFood>> $inStockByLabel
     *
     * @return array{?KnownFood, ?KnownFood}
     */
    private function food(array $entry, array $known, array $inStockByLabel): array
    {
        $key = is_string($entry['key'] ?? null) ? trim($entry['key']) : '';
        $label = is_string($entry['label'] ?? null) ? $entry['label'] : '';
        $named = $inStockByLabel[$this->normalizer->toWords($label)] ?? [];
        $byLabel = 1 === count($named) ? $named[0] : null;

        if ('' !== $key && isset($known[$key])) {
            $byKey = $known[$key];

            return null !== $byLabel && $byLabel->key !== $byKey->key ? [null, $byKey] : [$byKey, null];
        }

        return [$byLabel, null];
    }

    /** @param array<array-key, mixed> $entry */
    private static function labelOf(array $entry): string
    {
        foreach (['label', 'key'] as $field) {
            if (is_string($entry[$field] ?? null) && '' !== trim($entry[$field])) {
                return trim($entry[$field]);
            }
        }

        return 'Un aliment';
    }

    private static function countedAs(UnitFamily $family): string
    {
        return match ($family) {
            UnitFamily::Mass => 'au poids',
            UnitFamily::Volume => 'au volume',
            UnitFamily::Count => 'à l\'unité',
            UnitFamily::Serving => 'en portions',
        };
    }
}
