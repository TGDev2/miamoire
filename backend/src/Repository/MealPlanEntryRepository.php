<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Household;
use App\Entity\MealPlanEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<MealPlanEntry> */
class MealPlanEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MealPlanEntry::class);
    }

    public function findOwned(Household $household, string $id): ?MealPlanEntry
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $entry = $this->find(Uuid::fromString($id));

        return null !== $entry && $entry->belongsTo($household) ? $entry : null;
    }

    /**
     * Le tri chronologique complet (créneau puis heure d'ajout) se fait en PHP
     * dans `MealPlanService` : un créneau n'est pas alphabétique
     * (petit-déjeuner < déjeuner < dîner < goûter), la base ne peut pas le savoir.
     *
     * @return list<MealPlanEntry>
     */
    public function findForHousehold(Household $household): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.household = :household')
            ->setParameter('household', $household->getId(), 'uuid')
            ->orderBy('e.date', 'ASC')
            ->addOrderBy('e.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countForHousehold(Household $household): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.household = :household')
            ->setParameter('household', $household->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
