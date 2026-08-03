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
     * @param list<string>                 $locations
     * @param list<ProductTrait>           $traits    état réel du produit : en conserve, épluché, surgelé…
     * @param list<string>                 $notes     précisions saisies à la main sur les lots
     * @param list<InventoryDeadlineBucket> $buckets   la même quantité, ventilée par échéance effective
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
        /** Dérivé de `buckets` : vrai si au moins un groupe l'est. */
        public bool $expiresSoon,
        /** Dérivé de `buckets` : au moins un exemplaire est entamé, il passe avant. */
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
        public array $buckets = [],
        /**
         * Un aliment frais se dégrade dès l'achat, une conserve attend des années.
         * Les traiter à égalité tant que la date n'est pas proche, c'est perdre le
         * frais — le tri et le modèle en tiennent compte tous les deux.
         */
        public bool $perishable = false,
        /**
         * Poids indicatif d'une pièce, pour que le modèle puisse écrire « environ
         * 500 g de courgettes » tout en demandant « 2 pièces » au stock. Jamais une
         * conversion : `UnitConverter` refuse délibérément de franchir les familles
         * d'unité, et ce refus protège l'inventaire.
         */
        public ?int $approxPieceWeight = null,
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
            // Plutôt qu'un total assorti d'un booléen global : « 250 g entamés à
            // utiliser demain, 1 kg scellé plus tard » — pas de version dégradée
            // à un seul groupe, un seul chemin de code.
            'deadlines' => array_map(
                static fn (InventoryDeadlineBucket $bucket) => $bucket->toAiPayload(),
                $this->buckets,
            ),
        ];

        // Le frais passe avant le stable dès que les dates ne tranchent pas : une
        // courgette attend une semaine, une boîte de pois chiches attend deux ans.
        if ($this->perishable) {
            $payload['perishable'] = true;
        }

        if (null !== $this->approxPieceWeight && Unit::Piece === $this->unit) {
            $payload['approxWeightPerPiece'] = sprintf('%d g', $this->approxPieceWeight);
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
