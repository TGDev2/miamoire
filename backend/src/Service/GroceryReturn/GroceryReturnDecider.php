<?php

declare(strict_types=1);

namespace App\Service\GroceryReturn;

use App\Enum\FoodNature;
use App\Enum\GroceryReturnDecision;
use App\Enum\StorageLocation;

/**
 * Which question, if any, a resolved line still asks.
 *
 * Pure: it sees only facts the resolver established, so the rules can be
 * read and tested one by one. Order matters — a line asks at most one
 * question, the one without which the others are moot.
 */
final class GroceryReturnDecider
{
    /**
     * A chilled packaged food whose estimate is this short or shorter has a
     * printed date worth reading: it decides which lot is eaten first.
     */
    public const SHORT_DATE_DAYS = 10;

    public const STORAGE_GIVEN = 'given';
    public const STORAGE_HABIT = 'habit';
    public const STORAGE_DEFAULT = 'default';

    /**
     * @param string   $storageProvenance one of the STORAGE_* constants
     * @param int|null $estimatedDays     the estimate's length, or null when a
     *                                    printed date was given or the date was
     *                                    cleared on purpose
     */
    public function decide(
        bool $nameKnown,
        bool $unitMismatch,
        FoodNature $nature,
        StorageLocation $storage,
        string $storageProvenance,
        bool $isProtein,
        ?int $estimatedDays,
    ): GroceryReturnDecision {
        if (!$nameKnown) {
            return GroceryReturnDecision::UnknownFood;
        }

        if ($unitMismatch) {
            return GroceryReturnDecision::UnitMismatch;
        }

        // Meat and fish go either way, and the freezer stops the clock: when
        // neither the person nor a past purchase said where, ask. A habit is
        // an answer — the household already keeps this food somewhere.
        if ($isProtein && StorageLocation::Fridge === $storage && self::STORAGE_DEFAULT === $storageProvenance) {
            return GroceryReturnDecision::FridgeOrFreezer;
        }

        // Only a package carries a printed date, and only in the fridge does
        // a few days' error change what is eaten first. Fresh produce has no
        // date to read; the pantry has months of slack.
        if (!$nature->isFresh()
            && StorageLocation::Fridge === $storage
            && null !== $estimatedDays
            && $estimatedDays <= self::SHORT_DATE_DAYS
        ) {
            return GroceryReturnDecision::ShortDate;
        }

        return GroceryReturnDecision::None;
    }
}
