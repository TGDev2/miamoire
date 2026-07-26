<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Household;
use App\Entity\StockOperation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<StockOperation> */
class StockOperationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockOperation::class);
    }

    public function findOneByKey(Household $household, string $operationKey): ?StockOperation
    {
        return $this->findOneBy(['household' => $household, 'operationKey' => $operationKey]);
    }

    /**
     * Relit l'opération en contournant l'unité de travail Doctrine.
     *
     * Après une violation de contrainte unique, l'EntityManager est fermé et son
     * cache d'identité n'est plus fiable : c'est la base qu'il faut interroger,
     * pas la mémoire de la requête qui vient d'échouer.
     */
    public function findOneByKeyUncached(Household $household, string $operationKey): ?StockOperation
    {
        /** @var StockOperation|null $operation */
        $operation = $this->createQueryBuilder('o')
            ->andWhere('o.household = :household')
            ->andWhere('o.operationKey = :key')
            ->setParameter('household', $household->getId(), 'uuid')
            ->setParameter('key', $operationKey)
            ->getQuery()
            ->setHint(\Doctrine\ORM\Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $operation;
    }
}
