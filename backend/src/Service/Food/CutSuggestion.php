<?php

declare(strict_types=1);

namespace App\Service\Food;

use App\Enum\FoodCut;

/** The cut a purchase gets when nobody chose one, why, and the cuts the food can take. */
final readonly class CutSuggestion
{
    /** The label names the cut itself: "Escalopes de poulet". */
    public const SOURCE_LABEL = 'label';
    /** This household confirmed one cut for the same food, and only one. */
    public const SOURCE_HOUSEHOLD = 'household';
    /** A fresh fruit or vegetable, sold whole unless its label says otherwise. */
    public const SOURCE_PRODUCE = 'produce';

    /**
     * @param list<FoodCut> $plausible the cuts this food can take; empty for a dish
     *                                 or a food the lexicon does not know
     * @param bool          $matters   the cut decides the cooking (meat, fish): the
     *                                 only foods the app asks about
     */
    public function __construct(
        public ?FoodCut $cut,
        public ?string $source,
        public array $plausible,
        public bool $matters = false,
    ) {
    }
}
