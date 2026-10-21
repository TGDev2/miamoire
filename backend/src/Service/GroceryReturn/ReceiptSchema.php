<?php

declare(strict_types=1);

namespace App\Service\GroceryReturn;

/**
 * The JSON the model must return when it reads a receipt.
 *
 * Every field is required and never null, as in RecipeSchema: an unreadable
 * value is an empty string or a zero, which the reader treats as unknown.
 * Nothing in this JSON is written; it only feeds the resolver, and a human
 * confirms each line.
 */
final class ReceiptSchema
{
    /** @return array<string, mixed> */
    public static function definition(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['store', 'receiptDate', 'total', 'lines'],
            'properties' => [
                'store' => [
                    'type' => 'string',
                    'description' => "L'enseigne lue en haut du ticket ; chaîne vide si illisible.",
                ],
                'receiptDate' => [
                    'type' => 'string',
                    'description' => "La date d'achat au format AAAA-MM-JJ ; chaîne vide si illisible.",
                ],
                'total' => [
                    'type' => 'number',
                    'description' => 'Le montant total payé ; 0 si illisible.',
                ],
                'lines' => [
                    'type' => 'array',
                    'items' => self::line(),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function line(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['rawLabel', 'expandedName', 'isFood', 'quantity', 'packageQuantity', 'packageUnit', 'lineTotal'],
            'properties' => [
                'rawLabel' => [
                    'type' => 'string',
                    'description' => 'Le libellé tel qu\'imprimé, abréviations comprises, sans correction.',
                ],
                'expandedName' => [
                    'type' => 'string',
                    'description' => 'Le nom lisible de l\'aliment en français, au singulier, sans marque ni contenance ; chaîne vide si le libellé est illisible.',
                ],
                'isFood' => [
                    'type' => 'boolean',
                    'description' => 'Faux pour ce qui ne se mange ni ne se boit : papier, piles, entretien, sacs, consigne.',
                ],
                'quantity' => [
                    'type' => 'integer',
                    'description' => 'Le nombre d\'articles identiques de la ligne ; 1 par défaut, 3 pour « 3 x 1,20 ».',
                ],
                'packageQuantity' => [
                    'type' => 'number',
                    'description' => 'La contenance d\'un article quand la ligne la donne (125 pour « 6X125G », 0.842 pour « 0,842 KG ») ; 0 sinon.',
                ],
                'packageUnit' => [
                    'type' => 'string',
                    'enum' => ['', 'g', 'kg', 'ml', 'cl', 'l', 'piece'],
                    'description' => 'L\'unité de la contenance ; chaîne vide sans contenance.',
                ],
                'lineTotal' => [
                    'type' => 'number',
                    'description' => 'Le montant de la ligne ; 0 si illisible.',
                ],
            ],
        ];
    }
}
