<?php

declare(strict_types=1);

namespace App\Service\MealPlan;

use App\Entity\MealPlanEntry;

/**
 * Un autre repas planifié qui réclame la même variante — informatif, jamais
 * un signal de pénurie : « ce lait est également prévu pour mardi » vaut même
 * quand les deux repas sont par ailleurs couverts.
 */
final readonly class MealPlanCrossReference
{
    public function __construct(
        public string $entryId,
        public string $displayName,
        public \DateTimeImmutable $date,
        public string $slotLabel,
    ) {
    }

    public static function fromEntry(MealPlanEntry $entry): self
    {
        return new self(
            (string) $entry->getId(),
            $entry->displayName(),
            $entry->getDate(),
            $entry->getSlot()->label(),
        );
    }
}
