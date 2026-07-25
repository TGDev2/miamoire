<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Household;
use App\Entity\Member;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Member> */
class MemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Member::class);
    }

    /** @return list<Member> */
    public function findForHousehold(Household $household): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.household = :household')
            ->setParameter('household', $household->getId(), 'uuid')
            ->orderBy('m.firstName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByFirstName(Household $household, string $firstName): ?Member
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.household = :household')
            ->andWhere('LOWER(m.firstName) = :name')
            ->setParameter('household', $household->getId(), 'uuid')
            ->setParameter('name', mb_strtolower(trim($firstName)))
            ->getQuery()
            ->getOneOrNullResult();
    }
}
