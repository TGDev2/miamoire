<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StockItem;
use App\Entity\StockMovement;
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
}
