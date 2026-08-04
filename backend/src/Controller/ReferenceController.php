<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\Allergen;
use App\Enum\Equipment;
use App\Enum\FillLevel;
use App\Enum\FoodCut;
use App\Enum\MealTemperature;
use App\Enum\ProductTrait;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Http\RequestContext;
use App\Repository\StockItemRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Valeurs de référence consommées par les formulaires de l'application mobile. */
#[Route('/api/reference')]
class ReferenceController extends AbstractApiController
{
    #[Route('', methods: ['GET'])]
    public function index(RequestContext $context, StockItemRepository $stockItems): JsonResponse
    {
        return $this->json([
            'units' => array_map(
                static fn (Unit $unit): array => [
                    'value' => $unit->value,
                    'label' => $unit->label(),
                    'family' => $unit->family()->value,
                    'isDiscrete' => $unit->isDiscrete(),
                ],
                Unit::cases(),
            ),
            'storageLocations' => array_map(
                static fn (StorageLocation $location): array => [
                    'value' => $location->value,
                    'label' => $location->label(),
                ],
                StorageLocation::cases(),
            ),
            'fillLevels' => array_map(
                static fn (FillLevel $level): array => [
                    'value' => $level->value,
                    'label' => $level->label(),
                    'ratio' => $level->ratio(),
                ],
                FillLevel::cases(),
            ),
            'productTraits' => array_map(
                static fn (ProductTrait $trait): array => [
                    'value' => $trait->value,
                    'label' => $trait->label(),
                ],
                ProductTrait::cases(),
            ),
            // La liste complète, pour l'écran de correction. À la saisie, seules
            // les découpes plausibles pour l'aliment sont proposées — elles
            // arrivent avec sa fiche, via `GET /api/products/profile`.
            'productCuts' => array_map(
                static fn (FoodCut $cut): array => [
                    'value' => $cut->value,
                    'label' => $cut->label(),
                ],
                FoodCut::cases(),
            ),
            // Les exclusions proposées à la sélection plutôt que saisies à la main :
            // « arachide » choisi dans cette liste couvre aussi « beurre de
            // cacahuète » et « satay », ce qu'un texte libre ne peut pas faire.
            'allergens' => array_map(
                static fn (Allergen $allergen): array => [
                    'value' => $allergen->value,
                    'label' => $allergen->label(),
                    'defaultSeverity' => $allergen->defaultSeverity()->value,
                ],
                Allergen::cases(),
            ),
            'categories' => $stockItems->findUsedCategories($context->household()),
            'mealTypes' => [
                ['value' => 'breakfast', 'label' => 'Petit-déjeuner'],
                ['value' => 'lunch', 'label' => 'Déjeuner'],
                ['value' => 'dinner', 'label' => 'Dîner'],
                ['value' => 'snack', 'label' => 'Goûter'],
            ],
            'mealTemperatures' => array_map(
                static fn (MealTemperature $temperature): array => [
                    'value' => $temperature->value,
                    'label' => $temperature->label(),
                ],
                MealTemperature::cases(),
            ),
            'equipment' => array_map(
                static fn (Equipment $equipment): array => [
                    'value' => $equipment->value,
                    'label' => $equipment->label(),
                ],
                Equipment::cases(),
            ),
        ]);
    }
}
