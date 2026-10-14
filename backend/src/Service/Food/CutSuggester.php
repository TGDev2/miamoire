<?php

declare(strict_types=1);

namespace App\Service\Food;

use App\Entity\Household;
use App\Enum\DataOrigin;
use App\Enum\FoodCut;
use App\Enum\FoodNature;
use App\Enum\ProductTrait;
use App\Repository\HouseholdProductVariantRepository;
use App\Service\IngredientKeyGenerator;

/**
 * The cut a purchase gets when nobody chose one.
 *
 * Reading the label alone left most foods without a cut — "Blanc de poulet",
 * "Carottes" — and the recipe generator then had to guess. Three sources, in
 * this order, each answering only when it knows:
 *
 * 1. the label's own words ({@see FoodCutDetector});
 * 2. the household's memory: the cut someone confirmed for the same food in
 *    the same state, if there is exactly one — two confirmed cuts are a
 *    choice to make, not a habit;
 * 3. fresh produce is whole: fruit and vegetables are sold uncut unless the
 *    label says so. Meat is not: breast, escalopes and strips are all sold raw.
 *
 * Every screen that shows a cut before saving asks this service, so what is
 * shown is what gets saved.
 */
class CutSuggester
{
    public function __construct(
        private readonly FoodCutDetector $detector,
        private readonly FoodCutLexicon $lexicon,
        private readonly IngredientKeyGenerator $keys,
        private readonly HouseholdProductVariantRepository $variants,
    ) {
    }

    /** @param list<ProductTrait> $traits */
    public function suggest(
        Household $household,
        string $label,
        ?string $category,
        array $traits,
        FoodNature $nature,
    ): CutSuggestion {
        $plausible = $this->lexicon->plausibleCuts($label);

        // A dish: recognised, and without any cut.
        if ([] === $plausible) {
            return new CutSuggestion(null, null, []);
        }

        $plausible ??= [];
        $matters = $this->lexicon->decidesCooking($label);

        $detected = $this->detector->detect($label, $category);
        if (null !== $detected) {
            return new CutSuggestion($detected, CutSuggestion::SOURCE_LABEL, $plausible, $matters);
        }

        $remembered = $this->remembered($household, $label, $traits);
        if (null !== $remembered) {
            return new CutSuggestion($remembered, CutSuggestion::SOURCE_HOUSEHOLD, $plausible, $matters);
        }

        if ($nature->isFresh() && $this->lexicon->isProduce($label)) {
            return new CutSuggestion(FoodCut::Whole, CutSuggestion::SOURCE_PRODUCE, $plausible, $matters);
        }

        return new CutSuggestion(null, null, $plausible, $matters);
    }

    /** @param list<ProductTrait> $traits */
    private function remembered(Household $household, string $label, array $traits): ?FoodCut
    {
        $state = self::state($traits);
        $cuts = [];

        foreach ($this->variants->findByIngredientKey($household, $this->keys->generate($label)) as $variant) {
            if (null !== $variant->getCut()
                && DataOrigin::User === $variant->getCutOrigin()
                && self::state($variant->getTraits()) === $state
            ) {
                $cuts[$variant->getCut()->value] = $variant->getCut();
            }
        }

        return 1 === count($cuts) ? array_values($cuts)[0] : null;
    }

    /**
     * @param list<ProductTrait> $traits
     *
     * @return list<string>
     */
    private static function state(array $traits): array
    {
        $values = ProductTrait::toValues($traits);
        sort($values);

        return $values;
    }
}
