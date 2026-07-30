<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Household;
use App\Entity\StockItem;
use App\Entity\StockMovement;
use App\Enum\MovementType;
use App\Enum\StockOperationType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<StockMovement> */
class StockMovementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockMovement::class);
    }

    /** @return list<StockMovement> */
    public function findForStockItem(StockItem $item, int $limit = 100): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.stockItem = :item')
            ->setParameter('item', $item->getId(), 'uuid')
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Variantes consommées depuis `$since`, hors confirmations de recette —
     * celles-ci ont leur propre signal, {@see findVariantIdsRecentlyUsedInRecipe()}.
     *
     * @return list<string>
     */
    public function findVariantIdsRecentlyConsumed(Household $household, \DateTimeImmutable $since): array
    {
        return $this->recentConsumptionVariantIds($household, $since, viaRecipe: false);
    }

    /**
     * Variantes consommées depuis `$since` via une confirmation de recette.
     *
     * @return list<string>
     */
    public function findVariantIdsRecentlyUsedInRecipe(Household $household, \DateTimeImmutable $since): array
    {
        return $this->recentConsumptionVariantIds($household, $since, viaRecipe: true);
    }

    /** @return list<string> */
    private function recentConsumptionVariantIds(Household $household, \DateTimeImmutable $since, bool $viaRecipe): array
    {
        $qb = $this->createQueryBuilder('m')
            ->select('DISTINCT IDENTITY(s.variant) AS variantId')
            ->join('m.stockItem', 's')
            ->leftJoin('m.operation', 'o')
            ->andWhere('s.household = :household')
            ->andWhere('m.type = :type')
            ->andWhere('m.createdAt >= :since')
            ->setParameter('household', $household->getId(), 'uuid')
            ->setParameter('type', MovementType::Consumption)
            ->setParameter('since', $since);

        if ($viaRecipe) {
            $qb->andWhere('o.type = :recipeType')->setParameter('recipeType', StockOperationType::RecipeConfirmation);
        } else {
            $qb->andWhere('o.id IS NULL OR o.type != :recipeType')->setParameter('recipeType', StockOperationType::RecipeConfirmation);
        }

        /** @var list<array{variantId: string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return array_column($rows, 'variantId');
    }
}
