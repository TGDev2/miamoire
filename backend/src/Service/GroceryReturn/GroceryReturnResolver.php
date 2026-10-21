<?php

declare(strict_types=1);

namespace App\Service\GroceryReturn;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\Product;
use App\Entity\ShoppingListItem;
use App\Enum\FoodCut;
use App\Enum\FoodGroup;
use App\Enum\FoodNature;
use App\Enum\ProductTrait;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Http\JsonPayload;
use App\Repository\HouseholdProductVariantRepository;
use App\Repository\ProductRepository;
use App\Repository\ShoppingListItemRepository;
use App\Service\Food\CutSuggester;
use App\Service\Food\CutSuggestion;
use App\Service\Food\FoodGroupLexicon;
use App\Service\Food\FoodNatureDetector;
use App\Service\Food\FreshFoodLexicon;
use App\Service\Freshness\ShelfLifeEstimator;
use App\Service\IngredientKeyGenerator;
use App\Service\ProductTraitDetector;
use App\Service\UnitConverter;
use Symfony\Component\Uid\Uuid;

/**
 * Fills in the lines of a grocery return from what the household already
 * taught the app, and says what it could not fill.
 *
 * Deterministic and read-only: it never calls Open Food Facts (the scan
 * already did, and the product is in the catalogue or it is not), never
 * creates a variant, never writes. A value the person gave is kept as is;
 * only empty fields are filled, in this order of trust: the household's own
 * habits for this very product, the catalogue, the lexicons, the defaults.
 */
final class GroceryReturnResolver
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ShoppingListItemRepository $shoppingList,
        private readonly HouseholdProductVariantRepository $variants,
        private readonly IngredientKeyGenerator $keys,
        private readonly ProductTraitDetector $traitDetector,
        private readonly FoodNatureDetector $natureDetector,
        private readonly FreshFoodLexicon $freshFoods,
        private readonly CutSuggester $cutSuggester,
        private readonly ShelfLifeEstimator $shelfLife,
        private readonly FoodGroupLexicon $foodGroups,
        private readonly UnitConverter $converter,
        private readonly GroceryReturnDecider $decider,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rawLines
     *
     * @return list<ResolvedGroceryLine>
     */
    public function resolve(Household $household, array $rawLines): array
    {
        $resolved = [];

        foreach ($rawLines as $index => $raw) {
            $resolved[] = $this->resolveLine($household, JsonPayload::fromArray($raw), (string) $index);
        }

        return $resolved;
    }

    private function resolveLine(Household $household, JsonPayload $line, string $fallbackKey): ResolvedGroceryLine
    {
        $lineKey = $line->string('lineKey') ?? $fallbackKey;
        $product = $this->product($line);
        $listItem = $this->listItem($household, $line);
        $givenName = $line->string('name');
        $givenUnit = $line->enum('unit', Unit::class);
        $givenTraits = $line->has('traits') && null !== $line->raw()['traits']
            ? ProductTrait::fromValues($line->stringList('traits'))
            : null;

        // The product is the surest identity (the scan), then a food named
        // outright (a receipt label the household already taught), then the
        // list line (resolved to a variant when it was written), then the name.
        $variant = (null !== $product ? $this->variants->findOneByProduct($household, $product) : null)
            ?? $this->ownedVariant($household, $line->string('variantId'))
            ?? $listItem?->getVariant()
            ?? (null !== $givenName ? $this->variantByName($household, $givenName, $givenUnit, $givenTraits) : null);

        $name = $givenName ?? $variant?->getName() ?? $product?->getName() ?? $listItem?->getLabel() ?? '';
        $brand = $line->string('brand') ?? $variant?->getBrand() ?? $product?->getBrand();
        $category = $variant?->getCategory() ?? $product?->getCategory();
        $traits = $givenTraits ?? $this->traitsFor($variant, $product, $name, $category);

        $nature = $line->enum('nature', FoodNature::class)
            ?? $variant?->getNature()
            ?? ('' !== $name
                ? $this->natureDetector->detect($name, $category, $traits, scanned: null !== $product?->getBarcode())
                : FoodNature::Packaged);
        $fresh = '' !== $name ? $this->freshFoods->find($name) : null;

        // A unit from another family than the household's is not corrected
        // behind their back: the line asks (see GroceryReturnDecider).
        $unitMismatch = null !== $variant && null !== $givenUnit && $givenUnit->family() !== $variant->getUnitFamily();
        $habit = $this->usualPackage($variant);
        $unit = $givenUnit
            ?? $habit?->unit
            ?? $product?->getPackageUnit()
            ?? $fresh?->naturalUnit
            ?? $variant?->getUnitFamily()->baseUnit()
            ?? Unit::Gram;
        $unitFamily = $unitMismatch && null !== $variant ? $variant->getUnitFamily() : $unit->family();

        [$quantity, $packageProvenance] = $this->packageFor($line, $unit, $habit, $product, $nature);
        [$storage, $storageProvenance] = $this->storageFor($line, $variant, $name, $traits, $nature);

        $suggestion = '' !== $name
            ? $this->cutSuggester->suggest($household, $name, $category, $traits, $nature)
            : new CutSuggestion(null, null, []);
        $cut = $line->enum('cut', FoodCut::class) ?? $variant?->getCut() ?? $suggestion->cut;

        // A printed date is a fact and wins; a cleared date (`false`) is a
        // decision and is respected; anything else gets an estimate, flagged.
        $printed = $line->date('expirationDate');
        $estimateFlag = $line->has('estimateExpiration') && null !== $line->raw()['estimateExpiration']
            ? $line->bool('estimateExpiration')
            : null;
        $expiration = null;
        $estimated = false;
        $explanation = null;
        $estimatedDays = null;
        $dateProvenance = ResolvedGroceryLine::PROVENANCE_NONE;

        if (null !== $printed) {
            $expiration = $printed;
            $dateProvenance = ResolvedGroceryLine::PROVENANCE_PRINTED;
        } elseif (false !== $estimateFlag && '' !== $name) {
            $estimate = $this->shelfLife->estimate($name, $category, $traits, $storage);
            $expiration = $estimate->until();
            $estimated = true;
            $explanation = $estimate->explanation();
            $estimatedDays = $estimate->days;
            $dateProvenance = ResolvedGroceryLine::PROVENANCE_ESTIMATED;
        }

        $decision = $this->decider->decide(
            nameKnown: '' !== $name,
            unitMismatch: $unitMismatch,
            nature: $nature,
            storage: $storage,
            storageProvenance: $storageProvenance,
            isProtein: '' !== $name && in_array(FoodGroup::Protein, $this->foodGroups->groupsOf($name) ?? [], true),
            estimatedDays: $estimatedDays,
        );

        return new ResolvedGroceryLine(
            lineKey: $lineKey,
            decision: $decision,
            name: $name,
            brand: $brand,
            product: $product,
            barcode: $line->string('barcode') ?? $product?->getBarcode(),
            variant: $variant,
            shoppingListItem: $listItem,
            unit: $unit,
            unitFamily: $unitFamily,
            quantityPerPackage: $quantity,
            numberOfPackages: max(1, $line->int('numberOfPackages', 1) ?? 1),
            storageLocation: $storage,
            nature: $nature,
            traits: $traits,
            cut: $cut,
            plausibleCuts: $suggestion->plausible,
            cutMatters: $suggestion->matters,
            expirationDate: $expiration,
            expirationIsEstimated: $estimated,
            expirationExplanation: $explanation,
            storageProvenance: $storageProvenance,
            packageProvenance: $packageProvenance,
            dateProvenance: $dateProvenance,
        );
    }

    /** The catalogue product, from the cache only: the scan already asked Open Food Facts. */
    private function product(JsonPayload $line): ?Product
    {
        $productId = $line->string('productId');
        if (null !== $productId && Uuid::isValid($productId)) {
            $product = $this->products->find(Uuid::fromString($productId));
            if (null !== $product) {
                return $product;
            }
        }

        $barcode = $line->string('barcode');

        return null !== $barcode ? $this->products->findOneByBarcode($barcode) : null;
    }

    private function ownedVariant(Household $household, ?string $id): ?HouseholdProductVariant
    {
        if (null === $id || !Uuid::isValid($id)) {
            return null;
        }

        $variant = $this->variants->find(Uuid::fromString($id));

        return null !== $variant && $variant->getHousehold()->getId()->equals($household->getId()) ? $variant : null;
    }

    private function listItem(Household $household, JsonPayload $line): ?ShoppingListItem
    {
        $id = $line->string('shoppingListItemId');

        return null !== $id ? $this->shoppingList->findOwned($household, $id) : null;
    }

    /**
     * The household's variant for a typed name, when there is exactly one it
     * can be: the same key counted in another family, or kept in another
     * state, is another food — its habits must not leak onto this line.
     *
     * @param list<ProductTrait>|null $traits
     */
    private function variantByName(Household $household, string $name, ?Unit $unit, ?array $traits): ?HouseholdProductVariant
    {
        $candidates = $this->variants->findByIngredientKey($household, $this->keys->generate($name));

        if (null !== $unit) {
            $candidates = array_values(array_filter(
                $candidates,
                static fn (HouseholdProductVariant $variant): bool => $variant->getUnitFamily() === $unit->family(),
            ));
        }

        if (null !== $traits) {
            $wanted = ProductTrait::toValues($traits);
            sort($wanted);
            $candidates = array_values(array_filter(
                $candidates,
                static function (HouseholdProductVariant $variant) use ($wanted): bool {
                    $actual = ProductTrait::toValues($variant->getTraits());
                    sort($actual);

                    return $actual === $wanted;
                },
            ));
        }

        return 1 === count($candidates) ? $candidates[0] : null;
    }

    /** @return list<ProductTrait> */
    private function traitsFor(?HouseholdProductVariant $variant, ?Product $product, string $name, ?string $category): array
    {
        if (null !== $variant) {
            return $variant->getTraits();
        }

        if (null !== $product && [] !== $product->getTraits()) {
            return $product->getTraits();
        }

        return '' !== $name ? $this->traitDetector->detect($name, $category) : [];
    }

    /** The last package bought of this very food, in a readable unit of its family. */
    private function usualPackage(?HouseholdProductVariant $variant): ?UsualPackage
    {
        if (null === $variant || null === $variant->getUsualPackageSize()) {
            return null;
        }

        $readable = $this->converter->humanize($variant->getUsualPackageSize(), $variant->getUnitFamily()->baseUnit());

        return new UsualPackage($readable['quantity'], $readable['unit']);
    }

    /** @return array{0: ?float, 1: string} the quantity per package and where it comes from */
    private function packageFor(JsonPayload $line, Unit $unit, ?UsualPackage $habit, ?Product $product, FoodNature $nature): array
    {
        $given = $line->float('quantityPerPackage');
        if (null !== $given) {
            return [$given, ResolvedGroceryLine::PROVENANCE_GIVEN];
        }

        if (null !== $habit && $habit->unit->family() === $unit->family()) {
            return [round($this->converter->convert($habit->quantity, $habit->unit, $unit), 3), ResolvedGroceryLine::PROVENANCE_HABIT];
        }

        $catalogQuantity = $product?->getPackageQuantity();
        $catalogUnit = $product?->getPackageUnit();
        if (null !== $catalogQuantity && null !== $catalogUnit && $catalogUnit->family() === $unit->family()) {
            return [round($this->converter->convert($catalogQuantity, $catalogUnit, $unit), 3), ResolvedGroceryLine::PROVENANCE_CATALOG];
        }

        // A counted fresh food is one piece per package: three courgettes are
        // three packages of one, and nobody is asked a weight per courgette.
        if ($nature->isFresh() && $unit->isDiscrete()) {
            return [1.0, ResolvedGroceryLine::PROVENANCE_NONE];
        }

        return [null, ResolvedGroceryLine::PROVENANCE_NONE];
    }

    /**
     * @param list<ProductTrait> $traits
     *
     * @return array{0: StorageLocation, 1: string}
     */
    private function storageFor(JsonPayload $line, ?HouseholdProductVariant $variant, string $name, array $traits, FoodNature $nature): array
    {
        $given = $line->enum('storageLocation', StorageLocation::class);
        if (null !== $given) {
            return [$given, GroceryReturnDecider::STORAGE_GIVEN];
        }

        $usual = $variant?->getUsualStorageLocation();
        if (null !== $usual) {
            return [$usual, GroceryReturnDecider::STORAGE_HABIT];
        }

        if (in_array(ProductTrait::Frozen, $traits, true)) {
            return [StorageLocation::Freezer, GroceryReturnDecider::STORAGE_DEFAULT];
        }

        // Milk goes to the fridge and rice to the pantry whatever their
        // packaging: the shelf-life lexicon knows where each food is kept.
        $natural = '' !== $name ? $this->shelfLife->naturalLocation($name) : null;
        if (null !== $natural) {
            return [$natural, GroceryReturnDecider::STORAGE_DEFAULT];
        }

        return [$nature->isFresh() ? StorageLocation::Fridge : StorageLocation::Pantry, GroceryReturnDecider::STORAGE_DEFAULT];
    }
}
