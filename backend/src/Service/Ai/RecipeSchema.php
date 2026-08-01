<?php

declare(strict_types=1);

namespace App\Service\Ai;

/**
 * Schéma JSON imposé à la réponse du modèle.
 *
 * Les structured outputs garantissent une réponse exploitable sans parsing
 * approximatif — indispensable puisque cette réponse pilote ensuite une
 * proposition de déduction de stock.
 */
final class RecipeSchema
{
    public const FEASIBILITY_READY = 'ready';
    public const FEASIBILITY_ADAPTED = 'adapted';
    public const FEASIBILITY_NEEDS_SHOPPING = 'needs_shopping';

    /** @return array<string, mixed> */
    public static function definition(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['recipes'],
            'properties' => [
                'recipes' => [
                    'type' => 'array',
                    'items' => self::recipe(),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function recipe(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'recipeName', 'feasibility', 'servings', 'preparationTime', 'summary',
                'ingredientsFromInventory', 'missingIngredients', 'substitutions',
                'steps', 'stockConsumptionProposal', 'includesSideDish', 'utensils',
            ],
            'properties' => [
                'recipeName' => ['type' => 'string'],
                // Ustensiles et équipements réellement nécessaires à CETTE recette,
                // concrets plutôt que génériques — voir la règle correspondante du
                // prompt système.
                'utensils' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                // Vrai seulement quand un accompagnement a été ajouté parce que le
                // plat principal ne se suffisait pas à lui seul — voir la règle
                // correspondante du prompt système.
                'includesSideDish' => ['type' => 'boolean'],
                'feasibility' => [
                    'type' => 'string',
                    'enum' => [
                        self::FEASIBILITY_READY,
                        self::FEASIBILITY_ADAPTED,
                        self::FEASIBILITY_NEEDS_SHOPPING,
                    ],
                ],
                'servings' => ['type' => 'integer'],
                'preparationTime' => ['type' => 'integer'],
                'summary' => ['type' => 'string'],
                'ingredientsFromInventory' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['key', 'label', 'quantity', 'unit', 'essential'],
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'label' => ['type' => 'string'],
                            'quantity' => ['type' => 'number'],
                            'unit' => self::unitEnum(),
                            'essential' => ['type' => 'boolean'],
                        ],
                    ],
                ],
                'missingIngredients' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['label', 'quantity', 'unit', 'essential'],
                        'properties' => [
                            'label' => ['type' => 'string'],
                            'quantity' => ['type' => 'number'],
                            'unit' => self::unitEnum(),
                            'essential' => ['type' => 'boolean'],
                        ],
                    ],
                ],
                'substitutions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['original', 'replacedBy', 'reason'],
                        'properties' => [
                            'original' => ['type' => 'string'],
                            'replacedBy' => ['type' => 'string'],
                            'reason' => ['type' => 'string'],
                        ],
                    ],
                ],
                'steps' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'stockConsumptionProposal' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['key', 'label', 'quantity', 'unit'],
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'label' => ['type' => 'string'],
                            'quantity' => ['type' => 'number'],
                            'unit' => self::unitEnum(),
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function unitEnum(): array
    {
        return [
            'type' => 'string',
            'enum' => array_map(
                static fn (\App\Enum\Unit $unit) => $unit->value,
                \App\Enum\Unit::cases(),
            ),
        ];
    }
}
