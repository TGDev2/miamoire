<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Enum\FoodCut;
use App\Enum\ProductTrait;
use App\Enum\Unit;
use App\Service\Inventory\InventoryAggregator;
use App\Service\Inventory\InventoryLine;
use App\Service\Product\VariantResolver;

/**
 * Le défaut qui a motivé l'axe « découpe », vu de bout en bout.
 *
 * Une recette d'escalopes avait été écrite sur du blanc de poulet en tranches :
 * les deux portaient la clé `blanc-de-poulet` et le libellé « Blanc de poulet ».
 * Ce que ces tests garantissent n'est pas une consigne au modèle mais le fait
 * qu'il ne PUISSE plus les confondre — deux clés, deux libellés, deux lignes.
 */
class FoodCutInventoryTest extends DatabaseTestCase
{
    public function testTwoCutsOfTheSameFoodAreTwoInventoryLines(): void
    {
        $household = $this->makeHousehold();

        $escalopes = $this->makeVariant($household, 'Blanc de poulet', Unit::Piece, [], FoodCut::Escalope);
        $strips = $this->makeVariant($household, 'Blanc de poulet', Unit::Piece, [], FoodCut::Strips);

        $this->makeLot($household, $escalopes, 4, Unit::Piece, 'today +3 days');
        $this->makeLot($household, $strips, 300, Unit::Gram, 'today +3 days');
        $this->entityManager->flush();

        /** @var InventoryAggregator $aggregator */
        $aggregator = static::getContainer()->get(InventoryAggregator::class);
        $lines = $aggregator->aggregate($household);

        $labels = array_map(static fn (InventoryLine $line): string => $line->label, $lines);
        $keys = array_map(static fn (InventoryLine $line): string => $line->key, $lines);

        // Le nom seul ne dit pas la forme : elle s'ajoute entre parenthèses. Un
        // nom qui la dirait déjà — « Escalopes de poulet » — ne la répéterait pas.
        self::assertContains('Blanc de poulet (escalopes)', $labels);
        self::assertContains('Blanc de poulet (émincé)', $labels);
        self::assertContains('blanc-de-poulet-escalope', $keys);
        self::assertContains('blanc-de-poulet-emince', $keys);
    }

    /**
     * Ce que le modèle reçoit : la découpe seulement quand elle est connue.
     * `null` veut dire « on ignore la forme », jamais « entière » — et un champ
     * absent est plus clair pour lui qu'un champ à null.
     */
    public function testTheCutReachesTheModelOnlyWhenItIsKnown(): void
    {
        $household = $this->makeHousehold();

        $known = $this->makeVariant($household, 'Blanc de poulet', Unit::Piece, [], FoodCut::Escalope);
        $unknown = $this->makeVariant($household, 'Pates', Unit::Gram);

        $this->makeLot($household, $known, 4, Unit::Piece, 'today +3 days');
        $this->makeLot($household, $unknown, 500, Unit::Gram, 'today +200 days');
        $this->entityManager->flush();

        /** @var InventoryAggregator $aggregator */
        $aggregator = static::getContainer()->get(InventoryAggregator::class);

        $payloads = [];
        foreach ($aggregator->aggregate($household) as $line) {
            $payloads[$line->key] = $line->toAiPayload();
        }

        self::assertSame('escalope', $payloads['blanc-de-poulet-escalope']['cut']);
        self::assertArrayNotHasKey('cut', $payloads['pates']);

        // Une escalope pèse environ 150 g : c'est ce qui évite « 4 blancs de poulet »
        // devant quatre escalopes, et « 6 blancs » devant six tranches.
        self::assertSame('150 g', $payloads['blanc-de-poulet-escalope']['approxWeightPerPiece']);
    }

    /**
     * Trouvé en lançant l'application : la fiche affichait « Blanc de poulet en
     * tranches (en tranches) ». Un état que le nom énonce déjà n'apprend rien à
     * l'utilisateur, et encombre le libellé que lit le modèle.
     */
    public function testAStateTheNameAlreadySaysIsNotRepeated(): void
    {
        $household = $this->makeHousehold();

        $spelled = $this->makeVariant($household, 'Blanc de poulet en tranches', Unit::Gram, [], FoodCut::Slices);
        $silent = $this->makeVariant($household, 'Blanc de poulet', Unit::Piece, [], FoodCut::Slices);
        $partly = $this->makeVariant($household, 'Carottes surgelées', Unit::Gram, [ProductTrait::Frozen], FoodCut::Slices);
        $this->entityManager->flush();

        self::assertSame('Blanc de poulet en tranches', $spelled->getCulinaryLabel());
        self::assertSame('Blanc de poulet (en tranches)', $silent->getCulinaryLabel());
        // « surgelées » est déjà dit, « en tranches » ne l'est pas : seul ce qui
        // manque au nom vient le compléter.
        self::assertSame('Carottes surgelées (en tranches)', $partly->getCulinaryLabel());
    }

    /** Corriger la découpe réécrit la clé que recopie le générateur, pas seulement un libellé. */
    public function testCorrectingTheCutReidentifiesTheVariant(): void
    {
        $household = $this->makeHousehold();
        $variant = $this->makeVariant($household, 'Blanc de poulet', Unit::Piece, [], FoodCut::Escalope);
        $this->entityManager->flush();

        /** @var VariantResolver $resolver */
        $resolver = static::getContainer()->get(VariantResolver::class);
        $resolver->changeCut($variant, FoodCut::Strips);

        self::assertSame(FoodCut::Strips, $variant->getCut());
        self::assertSame('blanc-de-poulet-emince', $variant->getSlug());
        self::assertSame('Blanc de poulet (émincé)', $variant->getCulinaryLabel());
    }

    /**
     * Deux stocks distincts ne se fondent jamais en silence : corriger l'émincé en
     * « escalopes » alors que des escalopes existent déjà est refusé, et la raison
     * est dite. Réunir deux lignes est une décision, pas un effet de bord.
     */
    public function testCorrectingIntoAnExistingCutIsRefusedRatherThanMerged(): void
    {
        $household = $this->makeHousehold();
        $escalopes = $this->makeVariant($household, 'Blanc de poulet', Unit::Piece, [], FoodCut::Escalope);
        $strips = $this->makeVariant($household, 'Blanc de poulet', Unit::Piece, [], FoodCut::Strips);
        $this->makeLot($household, $escalopes, 4, Unit::Piece);
        $this->entityManager->flush();

        /** @var VariantResolver $resolver */
        $resolver = static::getContainer()->get(VariantResolver::class);

        $this->expectException(\App\Exception\StockException::class);
        $resolver->changeCut($strips, FoodCut::Escalope);
    }
}
