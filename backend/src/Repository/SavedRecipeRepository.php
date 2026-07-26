<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Household;
use App\Entity\SavedRecipe;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<SavedRecipe> */
class SavedRecipeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SavedRecipe::class);
    }

    /**
     * La plus récente en tête : on enregistre une recette pour la refaire bientôt.
     *
     * @return list<SavedRecipe>
     */
    public function findForHousehold(Household $household): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.household = :household')
            ->setParameter('household', $household->getId(), 'uuid')
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByFingerprint(Household $household, string $fingerprint): ?SavedRecipe
    {
        return $this->findOneBy(['household' => $household, 'fingerprint' => $fingerprint]);
    }

    /** Le filtre par foyer est ici et non chez l'appelant : c'est l'isolation du stock. */
    public function findOwned(Household $household, string $id): ?SavedRecipe
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $recipe = $this->find(Uuid::fromString($id));

        return null !== $recipe && $recipe->belongsTo($household) ? $recipe : null;
    }
}
