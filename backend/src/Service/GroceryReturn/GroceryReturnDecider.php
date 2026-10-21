<?php

declare(strict_types=1);

namespace App\Service\GroceryReturn;

use App\Enum\GroceryReturnDecision;
use App\Enum\StorageLocation;

/**
 * Which question, if any, a resolved line still asks.
 *
 * Pure: it sees only facts the resolver established, so the rules can be
 * read and tested one by one. Order matters — a line asks at most one
 * question, the one without which the others are moot.
 *
 * The printed date is deliberately not a question any more: a chilled
 * package gets an estimate, flagged as such and correctable, because asking
 * for the carton's date at every weekly milk purchase was the kind of
 * solicitation the return exists to remove.
 */
final class GroceryReturnDecider
{
    public const STORAGE_GIVEN = 'given';
    public const STORAGE_HABIT = 'habit';
    public const STORAGE_DEFAULT = 'default';

    /**
     * @param string $storageProvenance one of the STORAGE_* constants
     * @param bool   $freezerCandidate  raw meat, poultry, fish or seafood —
     *                                  see FreezerCandidates
     */
    public function decide(
        bool $nameKnown,
        bool $unitMismatch,
        StorageLocation $storage,
        string $storageProvenance,
        bool $freezerCandidate,
    ): GroceryReturnDecision {
        if (!$nameKnown) {
            return GroceryReturnDecision::UnknownFood;
        }

        if ($unitMismatch) {
            return GroceryReturnDecision::UnitMismatch;
        }

        // Meat and fish go either way, and the freezer stops the clock: when
        // neither the person nor a past purchase said where, ask. A habit is
        // an answer — the household already keeps this food somewhere — and a
        // food already headed to the freezer (a "surgelé" label) has nothing
        // to ask.
        if ($freezerCandidate && StorageLocation::Fridge === $storage && self::STORAGE_DEFAULT === $storageProvenance) {
            return GroceryReturnDecision::FridgeOrFreezer;
        }

        return GroceryReturnDecision::None;
    }
}
