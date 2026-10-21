<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The one question a grocery-return line may still ask a human.
 *
 * A return of thirty lines must cost a minute, so a line asks only when the
 * answer changes something downstream: what food it is (nothing can be
 * written otherwise), which unit family (the household counts this food
 * another way), or whether raw meat or fish goes to the freezer (the freezer
 * stops the clock). Everything else — the date included — is filled in and
 * shown as such: an estimate marked estimated is a value the person can
 * correct, not a question they must answer.
 */
enum GroceryReturnDecision: string
{
    case None = 'none';
    case UnknownFood = 'unknown_food';
    case UnitMismatch = 'unit_mismatch';
    case FridgeOrFreezer = 'fridge_or_freezer';

    /** The question shown on the line, in the household's language. */
    public function label(): ?string
    {
        return match ($this) {
            self::None => null,
            self::UnknownFood => 'Quel aliment est-ce ?',
            self::UnitMismatch => 'Dans quelle unité le compter ?',
            self::FridgeOrFreezer => 'Réfrigérateur ou congélateur ?',
        };
    }

    /** `ready` can be written as is; `unknown` cannot be written at all. */
    public function status(): string
    {
        return match ($this) {
            self::None => 'ready',
            self::UnknownFood => 'unknown',
            default => 'needs_decision',
        };
    }
}
