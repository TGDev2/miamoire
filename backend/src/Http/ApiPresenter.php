<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Product;
use App\Entity\StockItem;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Service\UnitConverter;

/** Mise en forme des entités pour l'API. Une seule source de vérité pour le contrat JSON. */
class ApiPresenter
{
    public function __construct(private readonly UnitConverter $converter)
    {
    }

    /** @return array<string, mixed> */
    public function user(User $user): array
    {
        return [
            'id' => (string) $user->getId(),
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            'dietaryExclusions' => $user->getDietaryExclusions(),
            'household' => [
                'id' => (string) $user->getHousehold()->getId(),
                'name' => $user->getHousehold()->getName(),
                'members' => array_map(
                    static fn (User $member): array => [
                        'id' => (string) $member->getId(),
                        'name' => $member->getName(),
                        'email' => $member->getEmail(),
                    ],
                    $user->getHousehold()->getMembers()->toArray(),
                ),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function product(Product $product): array
    {
        return [
            'id' => (string) $product->getId(),
            'barcode' => $product->getBarcode(),
            'name' => $product->getName(),
            'displayName' => $product->getDisplayName(),
            'ingredientKey' => $product->getIngredientKey(),
            'brand' => $product->getBrand(),
            'category' => $product->getCategory(),
            'packageQuantity' => $product->getPackageQuantity(),
            'packageUnit' => $product->getPackageUnit()?->value,
            'imageUrl' => $product->getImageUrl(),
            'source' => $product->getSource()->value,
        ];
    }

    /** @return array<string, mixed> */
    public function stockItem(StockItem $item): array
    {
        $readable = $this->converter->humanize($item->getRemainingQuantity(), $item->getUnit());

        return [
            'id' => (string) $item->getId(),
            'product' => $this->product($item->getProduct()),
            'initialQuantity' => round($item->getInitialQuantity(), 3),
            'remainingQuantity' => round($item->getRemainingQuantity(), 3),
            'unit' => $item->getUnit()->value,
            'unitLabel' => $item->getUnit()->label(),
            'isDiscreteUnit' => $item->getUnit()->isDiscrete(),
            'displayQuantity' => $readable['quantity'],
            'displayUnit' => $readable['unit']->label(),
            'numberOfPackages' => $item->getNumberOfPackages(),
            'storageLocation' => $item->getStorageLocation()->value,
            'storageLocationLabel' => $item->getStorageLocation()->label(),
            'purchaseDate' => $item->getPurchaseDate()?->format('Y-m-d'),
            'expirationDate' => $item->getExpirationDate()?->format('Y-m-d'),
            'daysUntilExpiration' => $item->getDaysUntilExpiration(),
            'openedAt' => $item->getOpenedAt()?->format(\DATE_ATOM),
            'isOpened' => $item->isOpened(),
            'quantityIsEstimated' => $item->isQuantityEstimated(),
            'status' => $item->getStatus(),
            'note' => $item->getNote(),
            'updatedAt' => $item->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function movement(StockMovement $movement): array
    {
        return [
            'id' => (string) $movement->getId(),
            'type' => $movement->getType()->value,
            'typeLabel' => $movement->getType()->label(),
            'quantity' => $movement->getQuantity(),
            'unit' => $movement->getUnit()->value,
            'remainingAfter' => $movement->getRemainingAfter(),
            'reason' => $movement->getReason(),
            'author' => $movement->getCreatedBy()?->getName(),
            'createdAt' => $movement->getCreatedAt()->format(\DATE_ATOM),
        ];
    }
}
