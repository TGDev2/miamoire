<?php

declare(strict_types=1);

namespace App\Service\GroceryReturn;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\Member;
use App\Entity\ShoppingListItem;
use App\Entity\StockItem;
use App\Entity\StockOperation;
use App\Enum\FoodCut;
use App\Enum\FoodNature;
use App\Enum\ProductTrait;
use App\Enum\StockOperationType;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Exception\GroceryReturnRejectedException;
use App\Exception\StockException;
use App\Http\ApiPresenter;
use App\Http\JsonPayload;
use App\Repository\ShoppingListItemRepository;
use App\Service\Product\VariantResolver;
use App\Service\Stock\StockIntake;
use App\Service\Stock\StockOperationGuard;
use App\Service\Stock\StockService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes a whole grocery return as one operation.
 *
 * Thirty lines, one key, one transaction: either every lot is in the stock
 * and every matched shopping-list line is gone, or nothing changed. A lost
 * answer followed by a second tap replays the first answer instead of
 * doubling the stock (see StockOperationGuard).
 *
 * Validation runs BEFORE the guard reserves the key. A line at fault is
 * reported with the others and nothing is written, so the person corrects
 * and confirms again with the same key at once — a validation failure
 * inside the guard would leave the key pending for two minutes.
 */
final class GroceryReturnService
{
    public const REASON = 'Retour de courses';

    public function __construct(
        private readonly StockOperationGuard $guard,
        private readonly StockService $stockService,
        private readonly StockIntake $intake,
        private readonly VariantResolver $variants,
        private readonly ShoppingListItemRepository $shoppingList,
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiPresenter $presenter,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rawLines
     *
     * @return array<string, mixed>
     */
    public function confirm(
        Household $household,
        Member $author,
        string $operationKey,
        string $requestHash,
        array $rawLines,
    ): array {
        if ([] === $rawLines) {
            throw new StockException('Le retour de courses est vide : rien à ranger.');
        }

        $errors = [];
        $prepared = [];

        foreach ($rawLines as $index => $raw) {
            $line = JsonPayload::fromArray($raw);
            $lineKey = $line->string('lineKey') ?? (string) $index;

            try {
                $prepared[] = $this->prepare($household, $line, $lineKey);
            } catch (StockException $exception) {
                $errors[] = ['lineKey' => $lineKey, 'message' => $exception->getMessage()];
            }
        }

        if ([] !== $errors) {
            throw new GroceryReturnRejectedException($errors);
        }

        return $this->guard->run(
            $household,
            $operationKey,
            StockOperationType::GroceryReturn,
            $requestHash,
            $author,
            $this->stockService,
            function (StockOperation $operation) use ($household, $author, $prepared): array {
                $items = [];
                $removed = [];

                foreach ($prepared as $entry) {
                    foreach ($entry->batches as $batch) {
                        $items[] = $this->intake->addBatch(
                            $household,
                            $author,
                            $entry->variant,
                            $entry->unit,
                            $entry->defaults,
                            $batch,
                            self::REASON,
                            $operation,
                        );
                    }

                    // The list line was a plan; the lot is the fact. Removed in
                    // the same transaction, so a return never half-ticks a list.
                    if (null !== $entry->shoppingListItem) {
                        $id = (string) $entry->shoppingListItem->getId();
                        if (!in_array($id, $removed, true)) {
                            $this->entityManager->remove($entry->shoppingListItem);
                            $removed[] = $id;
                        }
                    }
                }

                return [
                    'items' => array_map(fn (StockItem $item): array => $this->presenter->stockItem($item), $items),
                    'removedShoppingListItemIds' => $removed,
                ];
            },
        );
    }

    /**
     * Everything the line needs checked and resolved before any write.
     *
     * The variant is resolved here, as `POST /stock-items` does before its
     * own guard: a variant without a lot is harmless, a lot without its
     * variant would not be.
     */
    private function prepare(Household $household, JsonPayload $line, string $lineKey): PreparedGroceryLine
    {
        $name = $line->string('name');
        if (null === $name) {
            throw new StockException('Indiquez le nom de l\'aliment.');
        }

        $unit = $line->enum('unit', Unit::class)
            ?? throw new StockException('Indiquez l\'unité de cet aliment.');

        $quantityPerPackage = $line->float('quantityPerPackage');
        if (null === $quantityPerPackage || $quantityPerPackage <= 0) {
            throw new StockException('La quantité par exemplaire doit être strictement positive.');
        }

        $numberOfPackages = $line->int('numberOfPackages', 1) ?? 1;
        if ($numberOfPackages < 1) {
            throw new StockException('Le nombre d\'exemplaires doit être au moins égal à 1.');
        }

        $storage = $line->enum('storageLocation', StorageLocation::class)
            ?? throw new StockException('Indiquez où ranger cet aliment.');

        $batches = $line->objectList('batches');
        foreach ($batches as $batch) {
            $count = JsonPayload::fromArray($batch)->int('numberOfPackages', 1) ?? 1;
            if ($count < 1) {
                throw new StockException('Chaque lot doit compter au moins un exemplaire.');
            }
        }

        $product = $this->intake->catalogProduct($line->string('productId'), $line->string('barcode'), $name);

        $variant = $this->variants->resolve(
            household: $household,
            product: $product,
            name: $name,
            unit: $unit,
            explicitTraits: $line->has('traits') && null !== $line->raw()['traits']
                ? ProductTrait::fromValues($line->stringList('traits'))
                : null,
            brand: $line->string('brand'),
            explicitNature: $line->enum('nature', FoodNature::class),
            explicitCut: $line->enum('cut', FoodCut::class),
        );

        $listItemId = $line->string('shoppingListItemId');
        $listItem = null !== $listItemId ? $this->shoppingList->findOwned($household, $listItemId) : null;

        return new PreparedGroceryLine(
            lineKey: $lineKey,
            variant: $variant,
            unit: $unit,
            defaults: [
                'numberOfPackages' => $numberOfPackages,
                'quantityPerPackage' => $quantityPerPackage,
                'storageLocation' => $storage,
                'note' => $line->string('note'),
                'quantityIsEstimated' => $line->bool('quantityIsEstimated'),
                'expirationDate' => $line->date('expirationDate'),
                // `null` keeps the fresh-food rule of StockIntake: estimate unless
                // someone said not to. `false` is that someone.
                'estimateExpiration' => $line->has('estimateExpiration') && null !== $line->raw()['estimateExpiration']
                    ? $line->bool('estimateExpiration')
                    : null,
            ],
            batches: [] === $batches ? [[]] : $batches,
            shoppingListItem: $listItem,
        );
    }
}
