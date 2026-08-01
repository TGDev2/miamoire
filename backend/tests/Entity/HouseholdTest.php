<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Household;
use PHPUnit\Framework\TestCase;

/**
 * Une exclusion n'est plus un simple mot : sa sévérité change ce qu'elle
 * autorise à affirmer plus tard (voir `RecipePlanService`).
 */
class HouseholdTest extends TestCase
{
    public function testStoresLabelAndSeverity(): void
    {
        $household = new Household('Foyer de test');
        $household->setDietaryExclusions([
            ['label' => 'Lait et produits laitiers', 'severity' => 'allergy'],
            ['label' => 'Porc', 'severity' => 'diet'],
        ]);

        self::assertSame(
            [
                ['label' => 'Lait et produits laitiers', 'severity' => 'allergy'],
                ['label' => 'Porc', 'severity' => 'diet'],
            ],
            $household->getDietaryExclusions(),
        );
        self::assertSame(['Lait et produits laitiers', 'Porc'], $household->getDietaryExclusionLabels());
    }

    /** Seule l'allergie stricte doit empêcher une composition inconnue de se présenter comme sûre. */
    public function testOnlyAllergySeverityIsReturnedAsStrict(): void
    {
        $household = new Household('Foyer de test');
        $household->setDietaryExclusions([
            ['label' => 'Arachide', 'severity' => 'allergy'],
            ['label' => 'Alcool', 'severity' => 'preference'],
        ]);

        self::assertSame(['Arachide'], $household->getStrictExclusionLabels());
    }

    /** Une sévérité absente ou invalide retombe sur le défaut le plus sûr. */
    public function testMissingOrInvalidSeverityDefaultsToAllergy(): void
    {
        $household = new Household('Foyer de test');
        $household->setDietaryExclusions([
            ['label' => 'Brocoli', 'severity' => 'not-a-real-severity'],
            ['label' => 'Coriandre'],
        ]);

        self::assertSame(['Brocoli', 'Coriandre'], $household->getStrictExclusionLabels());
    }

    public function testBlankAndDuplicateLabelsAreDropped(): void
    {
        $household = new Household('Foyer de test');
        $household->setDietaryExclusions([
            ['label' => '  Lait  ', 'severity' => 'allergy'],
            ['label' => 'Lait', 'severity' => 'diet'],
            ['label' => '   ', 'severity' => 'allergy'],
        ]);

        self::assertSame([['label' => 'Lait', 'severity' => 'allergy']], $household->getDietaryExclusions());
    }

    public function testRecentRecipeSuggestionsStartEmpty(): void
    {
        $household = new Household('Foyer de test');

        self::assertSame([], $household->getRecentRecipeSuggestions());
    }

    public function testRememberedSuggestionsAreAppendedInOrder(): void
    {
        $household = new Household('Foyer de test');

        $household->rememberRecipeSuggestions(['Gratin dauphinois', 'Croziflette']);
        $household->rememberRecipeSuggestions(['Tarte au fromage']);

        self::assertSame(
            ['Gratin dauphinois', 'Croziflette', 'Tarte au fromage'],
            $household->getRecentRecipeSuggestions(),
        );
    }

    public function testBlankNamesAreDropped(): void
    {
        $household = new Household('Foyer de test');

        $household->rememberRecipeSuggestions(['Gratin', '  ', '']);

        self::assertSame(['Gratin'], $household->getRecentRecipeSuggestions());
    }

    /**
     * Le plafond garde le prompt court : seules les entrées les plus récentes
     * comptent pour éviter une répétition, pas tout ce que le foyer a jamais vu.
     */
    public function testHistoryIsCappedToTheMostRecentEntries(): void
    {
        $household = new Household('Foyer de test');

        for ($i = 1; $i <= 20; ++$i) {
            $household->rememberRecipeSuggestions([sprintf('Recette %d', $i)]);
        }

        $recent = $household->getRecentRecipeSuggestions();

        self::assertCount(15, $recent);
        self::assertSame('Recette 6', $recent[0], 'Les plus anciennes doivent être évincées en premier.');
        self::assertSame('Recette 20', $recent[14]);
    }
}
