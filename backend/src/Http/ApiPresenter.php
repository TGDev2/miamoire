<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\Member;
use App\Entity\Product;
use App\Entity\SavedRecipe;
use App\Entity\StockItem;
use App\Entity\StockMovement;
use App\Enum\ProductTrait;
use App\Service\Ai\RecipePlan;
use App\Service\Freshness\EffectiveUseByCalculator;
use App\Service\Freshness\ShelfLifeEstimate;
use App\Service\UnitConverter;

/** Mise en forme des entités pour l'API. Une seule source de vérité pour le contrat JSON. */
class ApiPresenter
{
    public function __construct(
        private readonly UnitConverter $converter,
        private readonly EffectiveUseByCalculator $effectiveUseBy,
    ) {
    }

    /** @return array<string, mixed> */
    public function household(Household $household): array
    {
        return [
            'id' => (string) $household->getId(),
            'name' => $household->getName(),
            'dietaryExclusions' => $household->getDietaryExclusions(),
            'mutedReviewCategories' => $household->getMutedReviewCategories(),
            'createdAt' => $household->getCreatedAt()->format(\DATE_ATOM),
            'members' => array_map(
                fn (Member $member) => $this->member($member),
                $household->getMembers()->toArray(),
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function member(Member $member): array
    {
        return [
            'id' => (string) $member->getId(),
            'firstName' => $member->getFirstName(),
            'householdId' => (string) $member->getHousehold()->getId(),
        ];
    }

    /** La fiche canonique, partagée entre foyers et jamais corrigée depuis un lot. */
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
            'traits' => ProductTrait::toValues($product->getTraits()),
            'packageQuantity' => $product->getPackageQuantity(),
            'packageUnit' => $product->getPackageUnit()?->value,
            'imageUrl' => $product->getImageUrl(),
            'allergensTags' => $product->getAllergensTags(),
            'tracesTags' => $product->getTracesTags(),
            'ingredientsText' => $product->getIngredientsText(),
            'source' => $product->getSource()->value,
        ];
    }

    /**
     * La variante du foyer : c'est elle que l'application affiche et modifie.
     *
     * @return array<string, mixed>
     */
    public function variant(HouseholdProductVariant $variant): array
    {
        $product = $variant->getProduct();

        return [
            'id' => (string) $variant->getId(),
            'key' => $variant->getSlug(),
            'name' => $variant->getName(),
            'displayName' => $variant->getDisplayName(),
            'culinaryLabel' => $variant->getCulinaryLabel(),
            'ingredientKey' => $variant->getIngredientKey(),
            'brand' => $variant->getBrand(),
            'category' => $variant->getCategory(),
            'traits' => ProductTrait::toValues($variant->getTraits()),
            'unitFamily' => $variant->getUnitFamily()->value,
            'nameOrigin' => $variant->getNameOrigin()->value,
            'traitsOrigin' => $variant->getTraitsOrigin()->value,
            // `null` : rien déclaré par ce foyer, on se fie au produit canonique.
            'allergensOverride' => $variant->getAllergensOverride(),
            // Ce qui vient du catalogue partagé et n'est pas corrigeable localement.
            'productId' => null !== $product ? (string) $product->getId() : null,
            'barcode' => $product?->getBarcode(),
            'imageUrl' => $product?->getImageUrl(),
            'catalogName' => $product?->getName(),
        ];
    }

    /**
     * @param list<StockItem> $siblings autres lots du même aliment
     *
     * @return array<string, mixed>
     */
    public function stockItem(StockItem $item, array $siblings = []): array
    {
        $readable = $this->converter->humanize($item->getRemainingQuantity(), $item->getUnit());
        $effective = $this->effectiveUseBy->compute($item);

        $payload = [
            'id' => (string) $item->getId(),
            // Renvoyée en `expectedRevision` par une correction absolue : le
            // serveur refuse alors d'écraser un changement qu'elle n'a pas vu.
            'revision' => $item->getRevision(),
            'variant' => $this->variant($item->getVariant()),
            'initialQuantity' => round($item->getInitialQuantity(), 3),
            'remainingQuantity' => round($item->getRemainingQuantity(), 3),
            'unit' => $item->getUnit()->value,
            'unitLabel' => $item->getUnit()->label(),
            'isDiscreteUnit' => $item->getUnit()->isDiscrete(),
            'displayQuantity' => $readable['quantity'],
            'displayUnit' => $readable['unit']->label(),
            'numberOfPackages' => $item->getNumberOfPackages(),
            'packageSize' => round($item->getPackageSize(), 3),
            'openPackageQuantity' => null !== $item->getOpenPackageQuantity()
                ? round($item->getOpenPackageQuantity(), 3)
                : null,
            'sealedPackageCount' => $item->getSealedPackageCount(),
            // Exemplaire par exemplaire, dans l'ordre où ils seront consommés :
            // « 250 g entamés, puis deux sachets de 500 g encore fermés ».
            'packages' => array_map(
                static fn (array $package): array => [
                    'position' => $package['position'],
                    'remaining' => $package['remaining'],
                    'opened' => $package['opened'],
                ],
                $item->getPackages(),
            ),
            'storageLocation' => $item->getStorageLocation()->value,
            'storageLocationLabel' => $item->getStorageLocation()->label(),
            'purchaseDate' => $item->getPurchaseDate()?->format('Y-m-d'),
            'expirationDate' => $item->getExpirationDate()?->format('Y-m-d'),
            'expirationIsEstimated' => $item->isExpirationEstimated(),
            'daysUntilExpiration' => $item->getDaysUntilExpiration(),
            'openedAt' => $item->getOpenedAt()?->format(\DATE_ATOM),
            'isOpened' => $item->isOpened(),
            // Entamé mais refermé sans être fini : reste prioritaire au même
            // titre qu'un exemplaire ouvert, juste rangé différemment.
            'isPackageResealed' => $item->isPackageResealed(),
            'quantityIsEstimated' => $item->isQuantityEstimated(),
            'isArchived' => $item->isArchived(),
            'archivedAt' => $item->getArchivedAt()?->format(\DATE_ATOM),
            'status' => $this->effectiveUseBy->status($item),
            // La plus proche de la date imprimée/estimée et de la durée de
            // conservation après ouverture : ce qui pilote réellement le stock,
            // Cuisiner et les notifications. `basis` dit laquelle a gagné ;
            // `afterOpeningDurationKnown` reste `null` quand la question ne se
            // pose pas (lot non entamé), et `false` plutôt qu'une valeur
            // inventée quand elle se pose mais qu'on ne la connaît pas.
            'effectiveUseBy' => $effective->date?->format('Y-m-d'),
            'effectiveUseByBasis' => $effective->basis,
            'afterOpeningDurationKnown' => $effective->afterOpeningDurationKnown,
            'daysUntilEffectiveUseBy' => $effective->daysFromToday(),
            'note' => $item->getNote(),
            'addedBy' => $item->getAddedBy()?->getFirstName(),
            'updatedAt' => $item->getUpdatedAt()->format(\DATE_ATOM),
        ];

        if ([] !== $siblings) {
            // « Vous avez aussi 2 autres lots, dont un qui périme le 27/07 » :
            // c'est ce qui permet de choisir le bon lot plutôt que d'en gonfler un.
            $payload['siblings'] = array_map(
                fn (StockItem $sibling): array => [
                    'id' => (string) $sibling->getId(),
                    'displayQuantity' => $this->converter->humanize($sibling->getRemainingQuantity(), $sibling->getUnit())['quantity'],
                    'displayUnit' => $this->converter->humanize($sibling->getRemainingQuantity(), $sibling->getUnit())['unit']->label(),
                    'expirationDate' => $sibling->getExpirationDate()?->format('Y-m-d'),
                    'expirationIsEstimated' => $sibling->isExpirationEstimated(),
                    'daysUntilExpiration' => $sibling->getDaysUntilExpiration(),
                    'storageLocationLabel' => $sibling->getStorageLocation()->label(),
                    'status' => $this->effectiveUseBy->status($sibling),
                ],
                $siblings,
            );
        }

        return $payload;
    }

    /**
     * Une recette conservée par le foyer.
     *
     * La recette elle-même est renvoyée telle qu'elle a été normalisée à
     * l'enregistrement : l'application affiche une recette enregistrée avec
     * exactement le même écran qu'une proposition fraîche.
     *
     * @return array<string, mixed>
     */
    public function savedRecipe(SavedRecipe $saved): array
    {
        return [
            'id' => (string) $saved->getId(),
            'recipeName' => $saved->getName(),
            'savedBy' => $saved->getSavedBy()?->getFirstName(),
            'savedAt' => $saved->getCreatedAt()->format(\DATE_ATOM),
            'schemaVersion' => $saved->getSchemaVersion(),
            'recipe' => $saved->getRecipe(),
        ];
    }

    /**
     * L'état actuel d'une recette, recalculé à l'instant de l'appel.
     *
     * @return array<string, mixed>
     */
    public function recipePlan(RecipePlan $plan): array
    {
        return [
            'status' => $plan->status->value,
            'reason' => $plan->reason,
            'lines' => array_map(
                static fn ($line): array => [
                    'key' => $line->key,
                    'label' => $line->label,
                    'status' => $line->status->value,
                    'detail' => $line->detail,
                ],
                $plan->lines,
            ),
            'checkedExclusions' => $plan->checkedExclusions,
            'evaluatedAt' => $plan->evaluatedAt->format(\DATE_ATOM),
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
            'author' => $movement->getCreatedBy()?->getFirstName(),
            // Les mouvements d'une même recette partagent cet identifiant :
            // c'est ce qui rend le journal lisible geste par geste.
            'operationId' => null !== $movement->getOperation()
                ? (string) $movement->getOperation()->getId()
                : null,
            'createdAt' => $movement->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function shelfLife(ShelfLifeEstimate $estimate): array
    {
        return [
            'days' => $estimate->days,
            'until' => $estimate->until()->format('Y-m-d'),
            'source' => $estimate->source,
            'matched' => $estimate->matched,
            'explanation' => $estimate->explanation(),
        ];
    }
}
