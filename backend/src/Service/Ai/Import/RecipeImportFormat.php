<?php

declare(strict_types=1);

namespace App\Service\Ai\Import;

use App\Enum\Unit;
use App\Service\Ai\RecipeSchema;

/**
 * The block another assistant writes at the end of a recipe, so the user can
 * bring that recipe back into the app.
 *
 * Its shape is exactly what `RecipeNormalizer::normalize()` keeps — no more:
 * `foodGroups` and `assumedCut` would be dropped on save, so they are not asked
 * for. Both prompts that leave the app (the export and the modification of one
 * recipe) describe it through `instructions()`, so they cannot drift apart.
 */
final class RecipeImportFormat
{
    /**
     * The example's name. A user who pastes the prompt together with the answer
     * must not import the example: the extractor skips it by this name.
     */
    public const EXAMPLE_NAME = 'Exemple de forme — ne pas importer';

    /** A placeholder that cannot collide with a real variant slug. */
    public const EXAMPLE_KEY = 'cle-exacte-des-donnees';

    public const EXAMPLE = [
        'recipeName' => self::EXAMPLE_NAME,
        'feasibility' => RecipeSchema::FEASIBILITY_READY,
        'servings' => 2,
        'preparationTime' => 25,
        'summary' => 'Une ou deux phrases.',
        'includesSideDish' => false,
        'utensils' => ['poêle'],
        'ingredientsFromInventory' => [
            ['key' => self::EXAMPLE_KEY, 'label' => 'Libellé « ingredient » des données', 'quantity' => 200, 'unit' => 'g', 'essential' => true],
        ],
        'missingIngredients' => [
            ['label' => 'Citron', 'quantity' => 1, 'unit' => 'piece', 'essential' => false],
        ],
        'substitutions' => [
            ['original' => 'Crème', 'replacedBy' => 'Lait', 'reason' => 'Pas de crème en stock.'],
        ],
        'steps' => ['Première étape.', 'Deuxième étape.'],
        'stockConsumptionProposal' => [
            ['key' => self::EXAMPLE_KEY, 'label' => 'Libellé « ingredient » des données', 'quantity' => 200, 'unit' => 'g'],
        ],
    ];

    public static function exampleJson(): string
    {
        return json_encode(self::EXAMPLE, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    /** The rules of the block, in the words given to the other assistant. */
    public static function instructions(): string
    {
        $units = implode(', ', array_map(static fn (Unit $unit): string => $unit->value, Unit::cases()));
        $feasibilities = implode(', ', [
            RecipeSchema::FEASIBILITY_READY,
            RecipeSchema::FEASIBILITY_ADAPTED,
            RecipeSchema::FEASIBILITY_NEEDS_SHOPPING,
        ]);
        $example = self::exampleJson();

        return <<<TEXT
            Chaque recette se termine par son bloc d'import : un bloc de code ```json qui
            contient un seul objet, de la forme exacte de l'exemple ci-dessous. C'est ce bloc,
            et lui seul, que je colle dans l'application pour y enregistrer la recette.

            - `key` : recopiée exactement depuis le champ `key` des données, jamais inventée,
              traduite ni raccourcie. Un aliment absent des données n'a pas de clé : il va
              dans `missingIngredients`.
            - `label` : pour un aliment de mon stock, son libellé `ingredient` dans les données.
            - `unit` : exactement l'une de ces valeurs : {$units}. Dans
              `stockConsumptionProposal`, une quantité dans l'unité de l'inventaire, ou une
              unité de la même famille (g et kg, ml, cl et l).
            - `feasibility` : {$feasibilities}.
            - `essential` : false pour un ingrédient facultatif.
            - Du JSON strict : guillemets droits, aucune virgule finale, aucun commentaire.

            Pendant toute la suite de notre conversation, chaque fois que tu proposes une
            nouvelle recette ou que tu en modifies une, termine-la par son bloc d'import
            complet et à jour — jamais un extrait, jamais « inchangé ».

            Exemple — la forme seulement, pas une recette à proposer :

            ```json
            {$example}
            ```
            TEXT;
    }
}
