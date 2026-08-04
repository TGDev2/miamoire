<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\MealPlanEntry;
use App\Entity\Member;
use App\Entity\StockItem;
use App\Entity\StockMovement;
use App\Entity\StockOperation;
use App\Enum\MealSlot;
use App\Enum\MovementType;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Service\Product\VariantIdentity;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Socle des tests qui exigent une vraie base.
 *
 * Les tests unitaires suffisent pour la logique pure ; la concurrence, elle, ne
 * se simule pas avec des doublures : un verrou qui n'est pas pris par PostgreSQL
 * ne se voit qu'en PostgreSQL. Ces tests sont donc **ignorés** si la base de test
 * n'est pas joignable, plutôt que d'échouer sur une machine sans Docker :
 *
 *     php bin/console doctrine:database:create --env=test
 *     php bin/console doctrine:migrations:migrate --env=test
 */
abstract class DatabaseTestCase extends KernelTestCase
{
    use RequiresDatabase;

    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;

        $this->skipUnlessDatabaseIsReachable($this->entityManager);
        $this->purge();
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            $this->entityManager->close();
        }

        parent::tearDown();
    }

    protected function purge(): void
    {
        $this->purgeDatabase($this->entityManager);
    }

    /**
     * Une seconde connexion, indépendante de l'unité de travail Doctrine.
     *
     * C'est elle qui joue « l'autre téléphone » : sans un second processus au sens
     * de la base, aucun verrou ne peut être observé.
     */
    protected function secondConnection(): Connection
    {
        return DriverManager::getConnection(
            $this->entityManager->getConnection()->getParams(),
        );
    }

    protected function makeHousehold(string $name = 'Foyer de test'): Household
    {
        $household = new Household($name);
        $household->setAccessKeyHash(hash('sha256', 'test-key-'.$name));
        $this->entityManager->persist($household);

        return $household;
    }

    protected function makeMember(Household $household, string $firstName = 'Theo'): Member
    {
        $member = new Member($household, $firstName);
        $this->entityManager->persist($member);

        return $member;
    }

    /** @param list<\App\Enum\ProductTrait> $traits */
    protected function makeVariant(
        Household $household,
        string $name,
        Unit $unit,
        array $traits = [],
        ?\App\Enum\FoodCut $cut = null,
    ): HouseholdProductVariant {
        $identity = VariantIdentity::of(
            preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)) ?? $name,
            $unit->family(),
            $traits,
            $cut,
        );

        $variant = new HouseholdProductVariant($household, $name, $identity, $identity->slugBase());
        $this->entityManager->persist($variant);

        return $variant;
    }

    protected function makeLot(
        Household $household,
        HouseholdProductVariant $variant,
        float $quantity,
        Unit $unit,
        ?string $expiresIn = null,
    ): StockItem {
        $item = new StockItem($household, $variant, $quantity, $unit, StorageLocation::Pantry);

        if (null !== $expiresIn) {
            $item->setExpirationDate(new \DateTimeImmutable($expiresIn));
        }

        $this->entityManager->persist($item);

        return $item;
    }

    protected function makeMovement(
        StockItem $item,
        MovementType $type = MovementType::Consumption,
        ?StockOperation $operation = null,
        float $quantity = -1.0,
    ): StockMovement {
        $movement = new StockMovement($item, $type, $quantity, $item->getUnit(), $item->getRemainingQuantity(), 'Test', null, $operation);
        $this->entityManager->persist($movement);

        return $movement;
    }

    /**
     * Une entrée de plan de repas — snapshot avec la proposition de
     * consommation donnée (clé = slug de variante), ou repas libre si `$label`
     * est fourni.
     *
     * @param list<array{key: string, label: string, quantity: float, unit: Unit}>|null $stockConsumptionProposal
     */
    protected function makeMealPlanEntry(
        Household $household,
        \DateTimeImmutable $date,
        MealSlot $slot,
        ?array $stockConsumptionProposal = null,
        ?string $label = null,
    ): MealPlanEntry {
        if (null !== $label) {
            $entry = MealPlanEntry::forFreeform($household, $date, $slot, $label);
        } else {
            $entry = MealPlanEntry::forSnapshot($household, $date, $slot, [
                'recipeName' => 'Recette de test',
                'feasibility' => 'ready',
                'servings' => 2,
                'preparationTime' => 10,
                'summary' => 'Une recette de test.',
                'includesSideDish' => false,
                'ingredientsFromInventory' => [],
                'missingIngredients' => [],
                'substitutions' => [],
                'steps' => ['Une étape.'],
                'stockConsumptionProposal' => array_map(
                    static fn (array $line): array => [
                        'key' => $line['key'],
                        'label' => $line['label'],
                        'quantity' => $line['quantity'],
                        'unit' => $line['unit']->value,
                    ],
                    $stockConsumptionProposal ?? [],
                ),
            ]);
        }

        $this->entityManager->persist($entry);

        return $entry;
    }

    /** La quantité telle que la base la voit, hors de tout cache d'entités. */
    protected function remainingInDatabase(StockItem $item): float
    {
        return (float) $this->secondConnection()->fetchOne(
            'SELECT remaining_quantity FROM stock_item WHERE id = ?',
            [(string) $item->getId()],
        );
    }
}
