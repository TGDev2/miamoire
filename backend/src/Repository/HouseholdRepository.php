<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Household;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Household> */
class HouseholdRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Household::class);
    }

    /**
     * Foyers avec leurs membres, pour l'écran de sélection au premier lancement.
     *
     * @return list<Household>
     */
    public function findAllWithCounts(): array
    {
        return $this->createQueryBuilder('h')
            ->addSelect('m')
            ->leftJoin('h.members', 'm')
            ->orderBy('h.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
