<?php

declare(strict_types=1);

namespace App\Service\OpenFoodFacts;

use App\Enum\Unit;

/** Fiche produit telle qu'Open Food Facts la renvoie, déjà nettoyée. */
final readonly class OpenFoodFactsProduct
{
    /**
     * @param list<string> $allergensTags identifiants de taxonomie OFF, ex. `en:milk`
     * @param list<string> $tracesTags    idem, pour les traces éventuelles
     */
    public function __construct(
        public string $barcode,
        public string $name,
        public ?string $brand = null,
        public ?string $category = null,
        public ?float $packageQuantity = null,
        public ?Unit $packageUnit = null,
        public ?string $imageUrl = null,
        public array $allergensTags = [],
        public array $tracesTags = [],
        public ?string $ingredientsText = null,
    ) {
    }
}
