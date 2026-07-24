<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\FillLevel;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Repository\StockItemRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Valeurs de référence consommées par les formulaires de l'application mobile. */
#[Route('/api/reference')]
class ReferenceController extends AbstractApiController
{
    #[Route('', methods: ['GET'])]
    public function index(StockItemRepository $stockItems): JsonResponse
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
            'categories' => $stockItems->findUsedCategories($this->currentHousehold()),
            'mealTypes' => [
                ['value' => 'breakfast', 'label' => 'Petit-déjeuner'],
                ['value' => 'lunch', 'label' => 'Déjeuner'],
                ['value' => 'dinner', 'label' => 'Dîner'],
                ['value' => 'snack', 'label' => 'Goûter'],
            ],
            'equipment' => [
                ['value' => 'stovetop', 'label' => 'Plaque / poêle'],
                ['value' => 'oven', 'label' => 'Four'],
                ['value' => 'microwave', 'label' => 'Micro-ondes'],
                ['value' => 'blender', 'label' => 'Mixeur'],
                ['value' => 'pressure_cooker', 'label' => 'Autocuiseur'],
                ['value' => 'airfryer', 'label' => 'Air fryer'],
            ],
        ]);
    }
}
