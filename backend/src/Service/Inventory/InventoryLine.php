<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Enum\Unit;

/** Un ingrédient agrégé, tous lots confondus. */
final readonly class InventoryLine
{
    /** @param list<string> $locations */
    public function __construct(
        public string $ingredientKey,
        public string $label,
        public float $quantity,
        public Unit $unit,
        public bool $expiresSoon,
        public ?\DateTimeImmutable $earliestExpiration,
        public ?string $category,
        public array $locations,
        public int $lotCount,
    ) {
    }

    /** @return array<string, mixed> */
    public function toAiPayload(): array
    {
        return [
            'key' => $this->ingredientKey,
            'ingredient' => $this->label,
            'quantity' => $this->quantity,
            'unit' => $this->unit->value,
            'expiresSoon' => $this->expiresSoon,
        ];
    }
}
