<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Enum\ProductTrait;
use App\Enum\Unit;

/** Un ingrédient agrégé, tous lots confondus. */
final readonly class InventoryLine
{
    /**
     * @param list<string>       $locations
     * @param list<ProductTrait> $traits    état réel du produit : en conserve, épluché, surgelé…
     * @param list<string>       $notes     précisions saisies à la main sur les lots
     */
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
        public array $traits = [],
        public array $notes = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toAiPayload(): array
    {
        $payload = [
            'key' => $this->ingredientKey,
            'ingredient' => $this->label,
            'quantity' => $this->quantity,
            'unit' => $this->unit->value,
            'expiresSoon' => $this->expiresSoon,
        ];

        // Ces trois champs décrivent l'aliment lui-même : sans eux, le modèle
        // propose d'éplucher des pommes de terre déjà pelées par le conserveur.
        if ([] !== $this->traits) {
            $payload['traits'] = ProductTrait::toValues($this->traits);
        }

        if (null !== $this->category) {
            $payload['category'] = $this->category;
        }

        if ([] !== $this->notes) {
            $payload['notes'] = $this->notes;
        }

        return $payload;
    }
}
