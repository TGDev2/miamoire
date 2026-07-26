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
     * Retrouve un foyer par l'empreinte de son code d'accès.
     *
     * C'est le seul moyen de rejoindre un foyer : il n'existe plus de route qui
     * énumère les foyers du serveur. La colonne est indexée et unique, la lecture
     * ne dépend donc pas du nombre de foyers.
     */
    public function findOneByAccessKeyHash(string $hash): ?Household
    {
        return $this->createQueryBuilder('h')
            ->addSelect('m')
            ->leftJoin('h.members', 'm')
            ->andWhere('h.accessKeyHash = :hash')
            ->setParameter('hash', $hash)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Foyers créés avant l'introduction des codes d'accès.
     *
     * @return list<Household>
     */
    public function findWithoutAccessKey(): array
    {
        return $this->createQueryBuilder('h')
            ->andWhere('h.accessKeyHash IS NULL')
            ->orderBy('h.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
