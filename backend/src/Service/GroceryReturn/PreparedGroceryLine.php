<?php

declare(strict_types=1);

namespace App\Service\GroceryReturn;

use App\Entity\HouseholdProductVariant;
use App\Entity\ShoppingListItem;
use App\Enum\StorageLocation;
use App\Enum\Unit;

/**
 * A line checked and resolved, waiting for the transaction.
 *
 * @internal to GroceryReturnService
 */
final readonly class PreparedGroceryLine
{
    /**
     * @param array{numberOfPackages: int, quantityPerPackage: float, storageLocation: StorageLocation, note: ?string, quantityIsEstimated: bool, expirationDate: ?\DateTimeImmutable, estimateExpiration: ?bool} $defaults
     * @param list<array<string, mixed>>                                                                                                                                                                               $batches
     * @param array{storeKey: string, labelKey: string, rawLabel: string}|null                                                                                                                                         $receipt the printed label this line came from, to be learnt
     */
    public function __construct(
        public string $lineKey,
        public HouseholdProductVariant $variant,
        public Unit $unit,
        public array $defaults,
        public array $batches,
        public ?ShoppingListItem $shoppingListItem,
        public ?array $receipt = null,
    ) {
    }
}
