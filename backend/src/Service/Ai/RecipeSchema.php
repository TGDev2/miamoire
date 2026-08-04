<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Enum\FoodCut;

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

    /** « Je n'ai supposé aucune forme » — parce que l'inventaire la donnait déjà. */
    public const CUT_NONE = 'none';

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
                        'required' => ['key', 'label', 'quantity', 'unit', 'essential', 'assumedCut'],
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'label' => ['type' => 'string'],
                            'quantity' => ['type' => 'number'],
                            'unit' => self::unitEnum(),
                            'essential' => ['type' => 'boolean'],
                            // La forme que la recette a supposée quand l'inventaire ne
                            // la donnait pas — voir la règle correspondante du prompt.
                            // C'est ce qui transforme la fiche recette en moment
                            // d'apprentissage : l'utilisateur voit la supposition à
                            // l'endroit exact où il peut la démentir.
                            'assumedCut' => self::cutEnum(),
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

    /**
     * Les découpes, plus « none ».
     *
     * Obligatoire et jamais nulle : un champ facultatif ou nullable se comporte
     * différemment selon le fournisseur, là où une énumération fermée est comprise
     * de la même façon par Claude et par Gemini, qui partagent ce schéma.
     *
     * @return array<string, mixed>
     */
    private static function cutEnum(): array
    {
        return [
            'type' => 'string',
            'enum' => [...FoodCut::toValues(FoodCut::cases()), self::CUT_NONE],
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
