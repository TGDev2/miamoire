<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\Member;
use App\Entity\Product;
use App\Entity\SavedRecipe;
use App\Entity\ShoppingListItem;
use App\Entity\StockItem;
use App\Entity\StockMovement;
use App\Enum\FoodNature;
use App\Enum\ProductTrait;
use App\Service\Ai\RecipePlan;
use App\Service\Food\FreshFood;
use App\Service\Freshness\EffectiveUseBy;
use App\Service\Freshness\EffectiveUseByCalculator;
use App\Service\Freshness\ShelfLifeEstimate;
use App\Service\MealPlan\MealPlanCrossReference;
use App\Service\MealPlan\MealPlanEntryView;
use App\Service\MealPlan\MealPlanLine;
use App\Service\MealPlan\MealPlanShortfallLine;
use App\Service\Shopping\ShoppingAdvisorCandidate;
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

        // Humanisé à la lecture : la valeur est conservée dans l'unité pivot
        // de la famille (grammes, millilitres...), jamais dans celle saisie
        // au dernier achat.
        $usualPackage = null !== $variant->getUsualPackageSize()
            ? $this->converter->humanize($variant->getUsualPackageSize(), $variant->getUnitFamily()->baseUnit())
            : null;

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
            // Frais ou conditionné : c'est ce qui décide du formulaire de saisie et
            // du vocabulaire de la fiche — une courgette n'a pas d'« exemplaire
            // entamé ». Voir App\Enum\FoodNature.
            'nature' => $variant->getNature()->value,
            'natureLabel' => $variant->getNature()->label(),
            'natureOrigin' => $variant->getNatureOrigin()->value,
            // `null` : rien déclaré par ce foyer, on se fie au produit canonique.
            'allergensOverride' => $variant->getAllergensOverride(),
            // Ce qui vient du catalogue partagé et n'est pas corrigeable localement.
            'productId' => null !== $product ? (string) $product->getId() : null,
            'barcode' => $product?->getBarcode(),
            'imageUrl' => $product?->getImageUrl(),
            'catalogName' => $product?->getName(),
            // Habitudes d'achat : `null` tant qu'aucun lot n'a jamais été créé.
            'usualPackageSize' => $usualPackage['quantity'] ?? null,
            'usualPackageUnit' => $usualPackage['unit']?->value ?? null,
            'usualStorageLocation' => $variant->getUsualStorageLocation()?->value,
            'usualStorageLocationLabel' => $variant->getUsualStorageLocation()?->label(),
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
    public function mealPlanEntry(MealPlanEntryView $view): array
    {
        $entry = $view->entry;

        return [
            'id' => (string) $entry->getId(),
            'date' => $entry->getDate()->format('Y-m-d'),
            'mealSlot' => $entry->getSlot()->value,
            'mealSlotLabel' => $entry->getSlot()->label(),
            'kind' => $entry->kind(),
            'displayName' => $entry->displayName(),
            'savedRecipeId' => null !== $entry->getSavedRecipe() ? (string) $entry->getSavedRecipe()->getId() : null,
            'label' => $entry->getLabel(),
            'recipe' => $entry->recipeDocument(),
            'addedBy' => $entry->getAddedBy()?->getFirstName(),
            'createdAt' => $entry->getCreatedAt()->format(\DATE_ATOM),
            'plan' => null !== $view->plan ? $this->recipePlan($view->plan) : null,
            'lines' => array_map(fn (MealPlanLine $line): array => $this->mealPlanLine($line), $view->lines),
        ];
    }

    /** @return array<string, mixed> */
    public function mealPlanLine(MealPlanLine $line): array
    {
        return [
            'key' => $line->key,
            'label' => $line->label,
            'requestedQuantity' => $line->requestedQuantity,
            'requestedUnit' => $line->requestedUnit->value,
            'status' => $line->status->value,
            'statusLabel' => $line->status->label(),
            'shortfall' => $line->shortfall,
            'detail' => $line->detail,
            'alsoClaimedBy' => array_map(
                static fn (MealPlanCrossReference $ref): array => [
                    'entryId' => $ref->entryId,
                    'displayName' => $ref->displayName,
                    'date' => $ref->date->format('Y-m-d'),
                    'slotLabel' => $ref->slotLabel,
                ],
                $line->alsoClaimedBy,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function mealPlanShortfallLine(MealPlanShortfallLine $line): array
    {
        return [
            'label' => $line->label,
            'quantity' => $line->quantity,
            'unit' => $line->unit->value,
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
    public function shoppingListItem(ShoppingListItem $item): array
    {
        return [
            'id' => (string) $item->getId(),
            'label' => $item->getLabel(),
            'quantity' => $item->getQuantity(),
            'unit' => $item->getUnit()->value,
            'unitLabel' => $item->getUnit()->label(),
            'checked' => $item->isChecked(),
            'checkedAt' => $item->getCheckedAt()?->format(\DATE_ATOM),
            'variantId' => null !== $item->getVariant() ? (string) $item->getVariant()->getId() : null,
            'addedBy' => $item->getAddedBy()?->getFirstName(),
            'createdAt' => $item->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function shoppingAdvice(ShoppingAdvisorCandidate $candidate, ?ShoppingListItem $existingShoppingListItem): array
    {
        return [
            'variant' => $this->variant($candidate->variant),
            'kind' => $candidate->kind->value,
            'kindLabel' => $candidate->kind->label(),
            'confidence' => $candidate->confidence->value,
            'confidenceLabel' => $candidate->confidence->label(),
            'headline' => $candidate->headline,
            'reasoning' => $candidate->reasoning,
            // Permet à l'écran de proposer directement « Retirer de ma liste » /
            // « Ajouter à la liste » sans recalculer ce rapprochement côté mobile.
            'existingShoppingListItemId' => null !== $existingShoppingListItem
                ? (string) $existingShoppingListItem->getId()
                : null,
        ];
    }

    /**
     * Ce qu'il y a déjà chez soi pour une variante — la partie « scan pendant
     * les courses » de l'Évolution 3 : quantité totale et prochaine échéance,
     * tous lots confondus.
     *
     * @param list<StockItem> $items lots disponibles de cette variante
     *
     * @return array<string, mixed>|null `null` : rien en stock actuellement
     */
    public function currentStockSummary(array $items): ?array
    {
        if ([] === $items) {
            return null;
        }

        $baseUnit = $items[0]->getUnit()->baseUnit();
        $base = 0.0;
        $nextUseBy = null;
        $nextUseByIsEstimated = false;

        foreach ($items as $item) {
            $base += $this->converter->toBase($item->getRemainingQuantity(), $item->getUnit());

            $effective = $this->effectiveUseBy->compute($item);
            if (null !== $effective->date && (null === $nextUseBy || $effective->date < $nextUseBy)) {
                $nextUseBy = $effective->date;
                $nextUseByIsEstimated = EffectiveUseBy::BASIS_PRINTED !== $effective->basis;
            }
        }

        $readable = $this->converter->humanize($base, $baseUnit);

        return [
            'quantity' => $readable['quantity'],
            'unit' => $readable['unit']->value,
            'unitLabel' => $readable['unit']->label(),
            'nextUseBy' => $nextUseBy?->format('Y-m-d'),
            'nextUseByIsEstimated' => $nextUseByIsEstimated,
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

    /**
     * Tout ce que le formulaire d'ajout a besoin de savoir avant de se dessiner.
     *
     * `naturalUnit` n'est renseignée que pour un aliment frais reconnu : c'est la
     * seule situation où l'application a mieux à proposer que « grammes ». Pour le
     * reste, elle se tait plutôt que d'inventer.
     *
     * @param FreshFood|null $fresh ce que le lexique sait de l'aliment, s'il le connaît
     *
     * @return array<string, mixed>
     */
    public function foodProfile(FoodNature $nature, ?FreshFood $fresh, ShelfLifeEstimate $shelfLife): array
    {
        return [
            'nature' => $nature->value,
            'natureLabel' => $nature->label(),
            'naturalUnit' => $fresh?->naturalUnit->value,
            'naturalUnitLabel' => $fresh?->naturalUnit->label(),
            // Sert au générateur de recettes, jamais à convertir : voir FreshFood.
            'approxPieceWeight' => $fresh?->approxPieceWeight,
            'shelfLife' => $this->shelfLife($shelfLife),
        ];
    }
}
