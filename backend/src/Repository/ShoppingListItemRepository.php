<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\ShoppingListItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<ShoppingListItem> */
class ShoppingListItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShoppingListItem::class);
    }

    /**
     * Non cochés d'abord — c'est ce qu'il reste à acheter — puis cochés, plus récents en tête.
     *
     * @return list<ShoppingListItem>
     */
    public function findForHousehold(Household $household): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.household = :household')
            ->setParameter('household', $household->getId(), 'uuid')
            ->orderBy('i.checked', 'ASC')
            ->addOrderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * La ligne active déjà associée à cette variante, quel que soit son état
     * coché — sert au dédoublonnage à l'ajout et au rapprochement au scan.
     */
    public function findOneActiveByVariant(Household $household, HouseholdProductVariant $variant): ?ShoppingListItem
    {
        return $this->findOneBy(['household' => $household, 'variant' => $variant]);
    }

    public function findOwned(Household $household, string $id): ?ShoppingListItem
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $item = $this->find(Uuid::fromString($id));

        return null !== $item && $item->getHousehold()->getId()->equals($household->getId()) ? $item : null;
    }
}
