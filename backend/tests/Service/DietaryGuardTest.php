<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Ai\Safety\DietaryGuard;
use App\Service\TextNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Le contrôle qui remplace une consigne par une garantie.
 *
 * Deux familles d'erreurs sont testées ici, et elles ont la même gravité :
 *
 * - le **faux négatif** met quelqu'un en danger — « beurre de cacahuète » non
 *   reconnu comme arachide ;
 * - le **faux positif** rend le contrôle inutilisable — « lait de coco » pris pour
 *   du lait écarterait presque toutes les recettes végétaliennes, jusqu'à ce que
 *   quelqu'un désactive l'exclusion. Ce qui revient au premier cas.
 *
 * Chaque contre-exemple de `Allergen::exceptions()` a son test (leçon n° 2).
 */
class DietaryGuardTest extends TestCase
{
    private function guard(string ...$exclusions): DietaryGuard
    {
        return new DietaryGuard(new TextNormalizer(), array_values($exclusions));
    }

    public function testRecognisesDerivedProductsOfAnAllergen(): void
    {
        $guard = $this->guard('arachide');

        // Personne n'écrit « arachide » dans une recette : c'est toute la difficulté.
        self::assertNotNull($guard->inspect('Ajouter le beurre de cacahuète', 'étape'));
        self::assertNotNull($guard->inspect('Sauce satay', 'titre'));
        self::assertNotNull($guard->inspect('cacahuètes grillées', 'ingrédient'));
    }

    public function testAnAliasActivatesTheWholeFamily(): void
    {
        // L'utilisateur a écrit « cacahuètes » ; « huile d'arachide » doit sortir.
        $guard = $this->guard('cacahuètes');

        self::assertNotNull($guard->inspect('Faire chauffer l\'huile d\'arachide', 'étape'));
    }

    /** Le contre-exemple le plus courant dans une recette adaptée. */
    public function testCoconutMilkIsNotMilk(): void
    {
        $guard = $this->guard('lait');

        self::assertNull($guard->inspect('Verser le lait de coco', 'étape'));
        self::assertNull($guard->inspect('crème de coco', 'ingrédient'));
        self::assertNull($guard->inspect('lait d\'amande', 'ingrédient'));
        self::assertNotNull($guard->inspect('Verser le lait entier', 'étape'), 'Le vrai lait doit rester détecté.');
    }

    public function testPeanutButterIsNotDairyButter(): void
    {
        $milkGuard = $this->guard('lait');
        $peanutGuard = $this->guard('arachide');

        self::assertNull($milkGuard->inspect('beurre de cacahuète', 'ingrédient'));
        self::assertNotNull($peanutGuard->inspect('beurre de cacahuète', 'ingrédient'));
        self::assertNotNull($milkGuard->inspect('une noisette de beurre', 'étape'));
    }

    public function testRiceFlourIsNotGluten(): void
    {
        $guard = $this->guard('gluten');

        self::assertNull($guard->inspect('100 g de farine de riz', 'ingrédient'));
        self::assertNotNull($guard->inspect('100 g de farine', 'ingrédient'));
    }

    /** « sans X » n'est jamais X — la règle vaut pour tous les termes. */
    public function testAnExplicitlyFreeFromMentionDoesNotTrigger(): void
    {
        self::assertNull($this->guard('gluten')->inspect('pain sans gluten', 'ingrédient'));
        self::assertNull($this->guard('arachide')->inspect('sauce sans arachide', 'ingrédient'));
        self::assertNull($this->guard('brocoli')->inspect('gratin sans brocoli', 'titre'));
    }

    /** Sans frontière de mot, « vin » sortirait sur « vinaigre » et « ble » sur « ensemble ». */
    public function testMatchingRespectsWordBoundaries(): void
    {
        self::assertNull($this->guard('alcool')->inspect('une cuillère de vinaigre', 'étape'));
        self::assertNull($this->guard('gluten')->inspect('Mélanger le tout ensemble', 'étape'));
        self::assertNotNull($this->guard('alcool')->inspect('déglacer au vin blanc', 'étape'));
    }

    /** Un vinaigre de vin reste du vinaigre. */
    public function testWineVinegarIsNotAlcohol(): void
    {
        self::assertNull($this->guard('alcool')->inspect('vinaigre de vin rouge', 'ingrédient'));
    }

    /** Au singulier c'est un ustensile, au pluriel un mollusque. */
    public function testCakeTinIsNotAMollusc(): void
    {
        $guard = $this->guard('mollusques');

        self::assertNull($guard->inspect('Verser dans un moule à gâteau', 'étape'));
        self::assertNull($guard->inspect('Beurrer le moule', 'étape'));
        self::assertNotNull($guard->inspect('Nettoyer les moules', 'étape'));
    }

    public function testCoconutIsNotATreeNut(): void
    {
        $guard = $this->guard('fruits à coque');

        self::assertNull($guard->inspect('noix de coco râpée', 'ingrédient'));
        self::assertNull($guard->inspect('une pincée de noix de muscade', 'étape'));
        self::assertNotNull($guard->inspect('noix de cajou', 'ingrédient'));
    }

    public function testFreeTextExclusionInventsNoSynonym(): void
    {
        $guard = $this->guard('brocoli');

        self::assertNotNull($guard->inspect('Faire cuire les brocolis', 'étape'), 'Le pluriel reste reconnu.');
        self::assertNull($guard->inspect('Faire cuire le chou-fleur', 'étape'), 'Aucun synonyme n\'est deviné.');
    }

    public function testVegetarianExclusionCatchesMeatButNotItsSubstitutes(): void
    {
        $guard = $this->guard('végétarien');

        self::assertNotNull($guard->inspect('Faire revenir le poulet', 'étape'));
        self::assertNull($guard->inspect('Faire revenir le steak de soja', 'étape'));
        self::assertNull($guard->inspect('Ajouter le bouillon de légumes', 'étape'));
    }

    public function testTheViolationSaysWhatWasFoundAndWhere(): void
    {
        $violation = $this->guard('arachide')->inspect('Servir avec une sauce satay', 'l\'étape 3');

        self::assertNotNull($violation);
        self::assertSame('Arachides', $violation->exclusion);
        self::assertSame('satay', $violation->matched);
        self::assertStringContainsString('l\'étape 3', $violation->describe());
    }

    public function testAnEmptyGuardLetsEverythingThrough(): void
    {
        $guard = $this->guard();

        self::assertTrue($guard->isEmpty());
        self::assertNull($guard->inspect('beurre de cacahuète et lait entier', 'ingrédient'));
    }

    public function testBlankExclusionsAreIgnored(): void
    {
        self::assertTrue($this->guard('', '   ')->isEmpty());
    }

    public function testInspectFieldsReportsTheOffendingLocation(): void
    {
        $violation = $this->guard('arachide')->inspectFields([
            'le titre' => 'Poulet grillé',
            'l\'étape' => ['Chauffer la poêle', 'Napper de sauce aux cacahuètes'],
        ]);

        self::assertNotNull($violation);
        self::assertSame('l\'étape 2', $violation->location);
    }
}
