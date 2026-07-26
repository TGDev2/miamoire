<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Enum\ProductTrait;
use App\Enum\Unit;

/**
 * Un ingrédient agrégé — tous les lots d'une même variante de foyer.
 *
 * « Tous les lots d'une même variante » et non « tous les lots d'une même clé » :
 * des tomates fraîches et des tomates en conserve font désormais deux lignes,
 * avec leurs quantités propres et leurs états propres.
 */
final readonly class InventoryLine
{
    /**
     * @param list<string>       $locations
     * @param list<ProductTrait> $traits    état réel du produit : en conserve, épluché, surgelé…
     * @param list<string>       $notes     précisions saisies à la main sur les lots
     */
    public function __construct(
        /** Identifiant lisible et stable, recopié par le générateur de recettes. */
        public string $key,
        public string $variantId,
        /** « Tomates (en conserve) » — ce que lit le modèle. */
        public string $label,
        /** « Tomates » — ce que lit l'utilisateur. */
        public string $name,
        public float $quantity,
        public Unit $unit,
        public bool $expiresSoon,
        /** Au moins un exemplaire est entamé : il se dégrade, il passe avant. */
        public bool $alreadyOpened,
        public ?\DateTimeImmutable $earliestExpiration,
        /** La date la plus proche a été déduite, pas lue sur un emballage. */
        public bool $expirationIsEstimated,
        /** Au moins un lot porte une quantité approximative. */
        public bool $quantityIsEstimated,
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
            'key' => $this->key,
            'ingredient' => $this->label,
            'quantity' => $this->quantity,
            'unit' => $this->unit->value,
            'expiresSoon' => $this->expiresSoon,
        ];

        // Un paquet ouvert se dégrade alors qu'un paquet scellé attend : le
        // signaler au modèle est ce qui lui fait terminer l'entamé plutôt que
        // d'en faire ouvrir un second.
        if ($this->alreadyOpened) {
            $payload['alreadyOpened'] = true;
        }

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

        // Une quantité approximative ne doit pas être consommée au gramme près :
        // le champ existait sur les lots depuis toujours mais n'était jamais transmis.
        if ($this->quantityIsEstimated) {
            $payload['quantityIsEstimated'] = true;
        }

        if ($this->expirationIsEstimated) {
            $payload['expirationIsEstimated'] = true;
        }

        return $payload;
    }
}
