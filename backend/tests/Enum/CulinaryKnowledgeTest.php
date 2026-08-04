<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\Equipment;
use App\Enum\FoodCut;
use App\Enum\ProductTrait;
use PHPUnit\Framework\TestCase;

/**
 * Trois enums portent la connaissance culinaire envoyée au modèle : l'état d'un
 * produit, sa forme, et l'appareil qui le cuit. Ajouter un cas sans écrire sa
 * consigne le ferait apparaître dans le prompt sans rien enseigner — un mot de
 * plus, et une cuisson de moins.
 */
class CulinaryKnowledgeTest extends TestCase
{
    public function testEveryTraitTeachesSomething(): void
    {
        foreach (ProductTrait::cases() as $trait) {
            self::assertNotSame('', $trait->instruction(), $trait->value);
            self::assertNotSame('', $trait->label(), $trait->value);
        }
    }

    public function testEveryCutTeachesSomething(): void
    {
        foreach (FoodCut::cases() as $cut) {
            self::assertNotSame('', $cut->instruction(), $cut->value);
            self::assertNotSame('', $cut->label(), $cut->value);
            self::assertNotSame('', $cut->slug(), $cut->value);
        }
    }

    public function testEveryEquipmentTeachesSomething(): void
    {
        foreach (Equipment::cases() as $equipment) {
            self::assertNotSame('', $equipment->instruction(), $equipment->value);
            self::assertNotSame('', $equipment->label(), $equipment->value);
        }
    }

    /** Deux découpes qui partageraient un fragment produiraient deux fois la même clé. */
    public function testCutSlugsAreUnique(): void
    {
        $slugs = array_map(static fn (FoodCut $cut) => $cut->slug(), FoodCut::cases());

        self::assertSame($slugs, array_unique($slugs));
    }

    /** Le barbecue est ce qui a motivé le passage de la liste littérale à un enum. */
    public function testTheNewEquipmentIsAvailable(): void
    {
        self::assertNotNull(Equipment::tryFrom('barbecue'));
        self::assertNotNull(Equipment::tryFrom('plancha'));
        self::assertNotNull(Equipment::tryFrom('multi_cooker'));
        self::assertTrue(Equipment::Barbecue->isOutdoor());
        self::assertFalse(Equipment::Oven->isOutdoor());
    }

    /** Une chaîne inconnue arrivait telle quelle dans le prompt : elle est écartée. */
    public function testUnknownEquipmentIsDropped(): void
    {
        self::assertSame(
            [Equipment::Oven, Equipment::Barbecue],
            Equipment::fromValues(['oven', 'lance-flammes', 'barbecue', 'oven']),
        );
    }
}
