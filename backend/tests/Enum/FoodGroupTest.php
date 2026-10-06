<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\FoodGroup;
use App\Service\Ai\RecipeSchema;
use PHPUnit\Framework\TestCase;

class FoodGroupTest extends TestCase
{
    /** Valeur inconnue écartée, doublon retiré, ordre de saisie conservé. */
    public function testFromValuesKeepsKnownGroupsOnceInOrder(): void
    {
        self::assertSame(
            [FoodGroup::Protein, FoodGroup::Starch],
            FoodGroup::fromValues(['protein', 'dessert', 'starch', 'protein']),
        );
        self::assertSame([], FoodGroup::fromValues([]));
    }

    /**
     * Le schéma imposé au modèle et l'enum vérifié par le filtre doivent dire
     * la même chose : un groupe que le modèle ne peut pas écrire ne serait
     * jamais trouvé, et toutes les recettes seraient écartées.
     */
    public function testTheResponseSchemaOffersExactlyTheKnownGroups(): void
    {
        $recipe = RecipeSchema::definition()['properties']['recipes']['items'];

        self::assertContains('foodGroups', $recipe['required']);
        self::assertSame(
            array_map(static fn (FoodGroup $group): string => $group->value, FoodGroup::cases()),
            $recipe['properties']['foodGroups']['items']['properties']['group']['enum'],
        );
    }
}
