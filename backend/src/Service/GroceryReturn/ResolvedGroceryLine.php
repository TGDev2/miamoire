<?php

declare(strict_types=1);

namespace App\Service\GroceryReturn;

use App\Entity\HouseholdProductVariant;
use App\Entity\Product;
use App\Entity\ShoppingListItem;
use App\Enum\FoodCut;
use App\Enum\FoodNature;
use App\Enum\GroceryReturnDecision;
use App\Enum\ProductTrait;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Enum\UnitFamily;

/**
 * One line of a grocery return, filled in as far as the household's own
 * data allows, with the origin of every value it did not type.
 *
 * Nothing here is written: the line is what the screen shows before the
 * person confirms, and the provenance is what lets them trust it — "comme
 * d'habitude" and "date estimée" are claims they can check, a bare value is
 * not (lesson 35: a default is not an observation).
 */
final readonly class ResolvedGroceryLine
{
    public const PROVENANCE_GIVEN = 'given';
    public const PROVENANCE_HABIT = 'habit';
    public const PROVENANCE_CATALOG = 'catalog';
    public const PROVENANCE_DEFAULT = 'default';
    public const PROVENANCE_NONE = 'none';
    public const PROVENANCE_PRINTED = 'printed';
    public const PROVENANCE_ESTIMATED = 'estimated';

    /**
     * @param list<ProductTrait> $traits
     * @param list<FoodCut>      $plausibleCuts
     */
    public function __construct(
        public string $lineKey,
        public GroceryReturnDecision $decision,
        public string $name,
        public ?string $brand,
        public ?Product $product,
        public ?string $barcode,
        public ?HouseholdProductVariant $variant,
        public ?ShoppingListItem $shoppingListItem,
        public Unit $unit,
        public UnitFamily $unitFamily,
        public ?float $quantityPerPackage,
        public int $numberOfPackages,
        public StorageLocation $storageLocation,
        public FoodNature $nature,
        public array $traits,
        public ?FoodCut $cut,
        public array $plausibleCuts,
        public bool $cutMatters,
        public ?\DateTimeImmutable $expirationDate,
        public bool $expirationIsEstimated,
        public ?string $expirationExplanation,
        public string $storageProvenance,
        public string $packageProvenance,
        public string $dateProvenance,
    ) {
    }
}
