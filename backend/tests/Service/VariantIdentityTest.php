<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\FoodCut;
use App\Enum\ProductTrait;
use App\Enum\Unit;
use App\Enum\UnitFamily;
use App\Service\Product\VariantIdentity;
use PHPUnit\Framework\TestCase;

/**
 * L'identité culinaire décide de ce qui s'additionne dans l'inventaire.
 *
 * Une erreur ici a deux formes, opposées et également graves : fusionner deux
 * stocks distincts — ce que faisait l'ancien regroupement par clé d'ingrédient —
 * ou scinder un stock unique, ce qui multiplierait les lignes pour rien.
 */
class VariantIdentityTest extends TestCase
{
    /** Le défaut d'origine : tomates fraîches et tomates en conserve, une seule ligne. */
    public function testStateSeparatesTwoIdentities(): void
    {
        $fresh = VariantIdentity::of('tomate', UnitFamily::Mass, []);
        $canned = VariantIdentity::of('tomate', UnitFamily::Mass, [ProductTrait::Canned]);

        self::assertNotSame($fresh->hash(), $canned->hash());
        self::assertSame('tomate', $fresh->slugBase());
        self::assertSame('tomate-conserve', $canned->slugBase());
    }

    /** Trois pièces et quatre cents grammes ne s'additionnent pas : ils ne se convertissent pas. */
    public function testUnitFamilySeparatesTwoIdentities(): void
    {
        $byWeight = VariantIdentity::of('tomate', UnitFamily::Mass, []);
        $byPiece = VariantIdentity::of('tomate', UnitFamily::Count, []);

        self::assertNotSame($byWeight->hash(), $byPiece->hash());
    }

    /** Deux saisies équivalentes doivent retomber sur la même variante, ordre compris. */
    public function testTraitOrderDoesNotChangeTheIdentity(): void
    {
        $first = VariantIdentity::of('pomme de terre', UnitFamily::Mass, [ProductTrait::Canned, ProductTrait::Peeled]);
        $second = VariantIdentity::of('pomme de terre', UnitFamily::Mass, [ProductTrait::Peeled, ProductTrait::Canned]);

        self::assertSame($first->hash(), $second->hash());
    }

    public function testDuplicateTraitsAreIgnored(): void
    {
        $once = VariantIdentity::of('epinard', UnitFamily::Mass, [ProductTrait::Frozen]);
        $twice = VariantIdentity::of('epinard', UnitFamily::Mass, [ProductTrait::Frozen, ProductTrait::Frozen]);

        self::assertSame($once->hash(), $twice->hash());
    }

    /**
     * Seul le trait qui change la nature de l'aliment nomme la variante :
     * une conserve est toujours précuite, l'écrire deux fois n'apprend rien.
     */
    public function testOnlyTheMostDefiningStateNamesTheVariant(): void
    {
        $identity = VariantIdentity::of(
            'pomme de terre',
            UnitFamily::Mass,
            [ProductTrait::Canned, ProductTrait::Precooked, ProductTrait::Peeled],
        );

        self::assertSame('pomme de terre-conserve', $identity->slugBase());
        self::assertSame(['en conserve'], $identity->stateLabels());
    }

    /** Un travail déjà fait n'est pas un aliment différent : il ne nomme pas la variante. */
    public function testPreparationTraitsDoNotNameTheVariant(): void
    {
        $identity = VariantIdentity::of('carotte', UnitFamily::Mass, [ProductTrait::Peeled]);

        self::assertSame('carotte', $identity->slugBase());
        self::assertSame([], $identity->stateLabels());
        // Elle reste néanmoins distincte d'une carotte non épluchée.
        self::assertNotSame(VariantIdentity::of('carotte', UnitFamily::Mass, [])->hash(), $identity->hash());
    }

    /**
     * Le défaut qui a motivé l'axe « découpe » : une recette d'escalopes écrite
     * sur du blanc de poulet en tranches, parce que les deux portaient la même
     * clé et le même libellé.
     */
    public function testCutSeparatesTwoIdentities(): void
    {
        $escalope = VariantIdentity::of('blanc de poulet', UnitFamily::Count, [], FoodCut::Escalope);
        $strips = VariantIdentity::of('blanc de poulet', UnitFamily::Count, [], FoodCut::Strips);

        self::assertNotSame($escalope->hash(), $strips->hash());
        self::assertSame('blanc de poulet-escalope', $escalope->slugBase());
        self::assertSame('blanc de poulet-emince', $strips->slugBase());
        self::assertSame(['escalopes'], $escalope->stateLabels());
    }

    /**
     * La garantie qui dispense de toute migration de hachage : sans découpe
     * déclarée, l'empreinte est celle que produisait l'application avant cet axe.
     * Si ce test tombe, tout l'inventaire enregistré se scinde en silence.
     */
    public function testAnUnknownCutKeepsThePreviousFingerprint(): void
    {
        $before = hash('sha256', implode('|', ['tomate', UnitFamily::Mass->value, 'canned']));

        self::assertSame(
            $before,
            VariantIdentity::of('tomate', UnitFamily::Mass, [ProductTrait::Canned])->hash(),
        );
    }

    /** L'état et la découpe se cumulent : du poulet surgelé émincé est bien les deux. */
    public function testStateAndCutCompose(): void
    {
        $identity = VariantIdentity::of('poulet', UnitFamily::Mass, [ProductTrait::Frozen], FoodCut::Strips);

        self::assertSame('poulet-surgele-emince', $identity->slugBase());
        self::assertSame(['surgelé', 'émincé'], $identity->stateLabels());
    }

    /**
     * Trouvé sur le vrai inventaire : « Oignons émincés » produit déjà la clé
     * `oignon-emince`, et lui recoller le fragment donnait `oignon-emince-emince`.
     * Un identifiant que le modèle recopie doit se lire.
     */
    public function testAFragmentAlreadyInTheKeyIsNotRepeated(): void
    {
        self::assertSame(
            'oignon-emince',
            VariantIdentity::of('oignon-emince', UnitFamily::Mass, [], FoodCut::Strips)->slugBase(),
        );
        self::assertSame(
            'carotte-surgele',
            VariantIdentity::of('carotte-surgele', UnitFamily::Mass, [ProductTrait::Frozen])->slugBase(),
        );
        // La clé garde le singulier du générateur là où la découpe est au pluriel :
        // `tranche` et `tranches` sont le même mot, pas deux précisions.
        self::assertSame(
            'blanc-poulet-tranche',
            VariantIdentity::of('blanc-poulet-tranche', UnitFamily::Mass, [], FoodCut::Slices)->slugBase(),
        );
        // Un mot court ne suffit jamais : `des` ne doit pas absorber `dessert`.
        self::assertSame(
            'dessert-des',
            VariantIdentity::of('dessert', UnitFamily::Mass, [], FoodCut::Cubes)->slugBase(),
        );
    }

    /** Changer la découpe ne touche à rien d'autre — c'est ce que fait une correction. */
    public function testWithCutKeepsEverythingElse(): void
    {
        $identity = VariantIdentity::of('poulet', UnitFamily::Mass, [ProductTrait::Frozen], FoodCut::Escalope);
        $corrected = $identity->withCut(FoodCut::Strips);

        self::assertSame($identity->ingredientKey, $corrected->ingredientKey);
        self::assertSame($identity->traits, $corrected->traits);
        self::assertSame(FoodCut::Strips, $corrected->cut);
        self::assertSame(
            VariantIdentity::of('poulet', UnitFamily::Mass, [ProductTrait::Frozen], FoodCut::Strips)->hash(),
            $corrected->hash(),
        );
    }

    public function testAnEmptyKeyFallsBackRatherThanProducingAnEmptyIdentity(): void
    {
        $identity = VariantIdentity::of('   ', UnitFamily::Count, []);

        self::assertSame('inconnu', $identity->ingredientKey);
        self::assertSame('inconnu', $identity->slugBase());
    }

    /** La famille se déduit de l'unité du lot : c'est ce lien qui alimente l'identité. */
    public function testFamilyComesFromTheUnitOfTheLot(): void
    {
        self::assertSame(
            VariantIdentity::of('lait', UnitFamily::Volume, [])->hash(),
            VariantIdentity::of('lait', Unit::Centiliter->family(), [])->hash(),
        );
    }
}
