<?php

declare(strict_types=1);

namespace App\Service\Food;

/**
 * The foods a household splits between fridge and freezer on the day they
 * are bought: raw meat, poultry, fish and seafood.
 *
 * The question "réfrigérateur ou congélateur ?" is worth a tap only for
 * them. Eggs, cheese, ham, tofu are proteins too, but nobody freezes a box
 * of eggs on the way back from the shop — asking would be noise, and a
 * return of thirty lines cannot afford noise.
 */
final class FreezerCandidates
{
    /** @var array<string, true> tolerant terms, see FoodTermMatcher */
    private const FOODS = [
        'viande' => true,
        'boeuf' => true,
        'steak' => true,
        'veau' => true,
        'porc' => true,
        'agneau' => true,
        'poulet' => true,
        'dinde' => true,
        'canard' => true,
        'lapin' => true,
        'escalope' => true,
        'cote' => true,
        'filet' => true,
        'roti' => true,
        'hache' => true,
        'lardon' => true,
        'saucisse' => true,
        'merguez' => true,
        'poisson' => true,
        'saumon' => true,
        'cabillaud' => true,
        'colin' => true,
        'merlu' => true,
        'lieu' => true,
        'truite' => true,
        'dorade' => true,
        'thon' => true,
        'sardine' => true,
        'maquereau' => true,
        'crevette' => true,
        'moule' => true,
        'saint jacques' => true,
        'calamar' => true,
    ];

    public function __construct(private readonly FoodTermMatcher $matcher)
    {
    }

    public function isCandidate(string $label): bool
    {
        return null !== $this->matcher->match($label, self::FOODS);
    }
}
