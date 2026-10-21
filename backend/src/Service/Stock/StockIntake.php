<?php

declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\Member;
use App\Entity\Product;
use App\Entity\StockItem;
use App\Entity\StockOperation;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Http\JsonPayload;
use App\Repository\ProductRepository;
use App\Service\Freshness\ShelfLifeEstimator;
use App\Service\OpenFoodFacts\OpenFoodFactsUnavailableException;
use App\Service\ProductLookupService;
use Symfony\Component\Uid\Uuid;

/**
 * What every way into the stock shares: the catalogue product behind a line,
 * the estimated date when nothing is printed, and the lot itself.
 *
 * Born in `StockItemController::create()`, where one request added the lots
 * of one food. A grocery return adds the lots of thirty foods in one
 * operation and must apply exactly the same rules, so the rules live here,
 * once, and both routes call them.
 */
final class StockIntake
{
    public function __construct(
        private readonly StockService $stockService,
        private readonly ShelfLifeEstimator $shelfLife,
        private readonly ProductLookupService $lookup,
        private readonly ProductRepository $products,
    ) {
    }

    /**
     * The canonical product, when there is one.
     *
     * A free-text entry (fruit, vegetables, bulk, leftovers, home cooking)
     * creates none: it creates a household variant and nothing else. The
     * shared catalogue is only ever filled through a barcode.
     *
     * A barcode unknown to Open Food Facts but completed with a typed name
     * still contributes a minimal product: without it, the same barcode would
     * come back as "unknown" at every later scan.
     */
    public function catalogProduct(?string $productId, ?string $barcode, ?string $name): ?Product
    {
        if (null !== $productId && Uuid::isValid($productId)) {
            $product = $this->products->find(Uuid::fromString($productId));
            if (null !== $product) {
                return $product;
            }
        }

        if (null === $barcode) {
            return null;
        }

        try {
            $product = $this->lookup->findByBarcode($barcode);
        } catch (OpenFoodFactsUnavailableException) {
            // Whether Open Food Facts knows this code is unknown: the lot goes
            // in without a product rather than freezing into the shared
            // catalogue a manual, never-refreshed product that would hide the
            // real one from every household.
            return null;
        }

        if (null !== $product) {
            return $product;
        }

        $name = trim((string) $name);
        if ('' === $name || 1 !== preg_match('/^\d{6,20}$/', $barcode)) {
            return null;
        }

        return $this->lookup->createManual(name: $name, barcode: $barcode);
    }

    /**
     * One lot: the batch's own values first, the request's defaults otherwise.
     *
     * A fresh food carries no printed date. Without an explicit instruction it
     * is estimated as a matter of course: without that it went in undated and
     * stayed invisible to the alerts, Today and the recipes — the food that
     * rotted was precisely the one the app could not see. An explicit `false`
     * is respected: someone decided.
     *
     * @param array{numberOfPackages: int, quantityPerPackage: float, storageLocation: StorageLocation, note: ?string, quantityIsEstimated: bool, expirationDate: ?\DateTimeImmutable, estimateExpiration: ?bool} $defaults
     * @param array<string, mixed>                                                                                                                                                                               $batch
     */
    public function addBatch(
        Household $household,
        Member $author,
        HouseholdProductVariant $variant,
        Unit $unit,
        array $defaults,
        array $batch,
        ?string $reason = null,
        ?StockOperation $operation = null,
    ): StockItem {
        $line = JsonPayload::fromArray($batch);

        $location = $line->enum('storageLocation', StorageLocation::class) ?? $defaults['storageLocation'];
        $purchaseDate = $line->date('purchaseDate');
        $expiration = $line->has('expirationDate') ? $line->date('expirationDate') : $defaults['expirationDate'];
        $estimate = $line->has('estimateExpiration') ? $line->bool('estimateExpiration') : $defaults['estimateExpiration'];

        $estimate ??= $variant->isFresh();

        // A date read on the package always wins: only what is missing is estimated.
        $estimated = false;
        if (null === $expiration && $estimate) {
            $expiration = $this->estimateFor($variant, $location, $purchaseDate);
            $estimated = true;
        }

        return $this->stockService->add(
            household: $household,
            author: $author,
            variant: $variant,
            numberOfPackages: $line->int('numberOfPackages') ?? $defaults['numberOfPackages'],
            quantityPerPackage: $line->float('quantityPerPackage') ?? $defaults['quantityPerPackage'],
            unit: $unit,
            location: $location,
            purchaseDate: $purchaseDate,
            expirationDate: $expiration,
            note: $line->string('note') ?? $defaults['note'],
            quantityIsEstimated: $line->has('quantityIsEstimated')
                ? $line->bool('quantityIsEstimated')
                : $defaults['quantityIsEstimated'],
            expirationIsEstimated: $estimated,
            reason: $reason,
            operation: $operation,
        );
    }

    /**
     * `$from` starts the estimated duration at the purchase date when it is
     * known, rather than today: a food bought three days ago does not keep
     * three days longer just because it was typed in today.
     */
    public function estimateFor(
        HouseholdProductVariant $variant,
        StorageLocation $location,
        ?\DateTimeImmutable $from = null,
    ): \DateTimeImmutable {
        return $this->shelfLife->estimate(
            $variant->getName(),
            $variant->getCategory(),
            $variant->getTraits(),
            $location,
        )->until($from);
    }
}
