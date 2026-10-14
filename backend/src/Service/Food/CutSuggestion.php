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
     * @param list<FoodCut> $plausible empty when the cut does not matter for this food
     */
    public function __construct(
        public ?FoodCut $cut,
        public ?string $source,
        public array $plausible,
    ) {
    }
}
