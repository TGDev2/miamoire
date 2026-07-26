<?php

declare(strict_types=1);

namespace App\Tests\Service;

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
        self::assertSame('en conserve', $identity->stateLabel());
    }

    /** Un travail déjà fait n'est pas un aliment différent : il ne nomme pas la variante. */
    public function testPreparationTraitsDoNotNameTheVariant(): void
    {
        $identity = VariantIdentity::of('carotte', UnitFamily::Mass, [ProductTrait::Peeled, ProductTrait::Sliced]);

        self::assertSame('carotte', $identity->slugBase());
        self::assertNull($identity->stateLabel());
        // Elle reste néanmoins distincte d'une carotte entière.
        self::assertNotSame(VariantIdentity::of('carotte', UnitFamily::Mass, [])->hash(), $identity->hash());
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
