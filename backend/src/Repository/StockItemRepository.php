<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Household;
use App\Entity\StockItem;
use App\Enum\StorageLocation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<StockItem> */
class StockItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockItem::class);
    }

    /**
     * @param array{
     *     location?: StorageLocation|null,
     *     category?: string|null,
     *     search?: string|null,
     *     expiringWithinDays?: int|null,
     *     includeEmpty?: bool
     * } $filters
     *
     * @return list<StockItem>
     */
    public function search(Household $household, array $filters = []): array
    {
        $qb = $this->baseQuery($household, $filters['includeEmpty'] ?? false);

        if (($filters['location'] ?? null) instanceof StorageLocation) {
            $qb->andWhere('s.storageLocation = :location')
                ->setParameter('location', $filters['location']);
        }

        if (null !== ($filters['category'] ?? null) && '' !== $filters['category']) {
            $qb->andWhere('p.category = :category')
                ->setParameter('category', $filters['category']);
        }

        if (null !== ($filters['search'] ?? null) && '' !== $filters['search']) {
            // Parenthèses explicites : sans elles le OR absorberait les filtres précédents.
            $qb->andWhere('(LOWER(p.name) LIKE :search OR LOWER(p.brand) LIKE :search OR p.ingredientKey LIKE :search)')
                ->setParameter('search', '%'.mb_strtolower($filters['search']).'%');
        }

        if (null !== ($filters['expiringWithinDays'] ?? null)) {
            $qb->andWhere('s.expirationDate IS NOT NULL AND s.expirationDate <= :limitDate')
                ->setParameter('limitDate', new \DateTimeImmutable(sprintf('today +%d days', $filters['expiringWithinDays'])));
        }

        return $this->applyFefoOrder($qb)->getQuery()->getResult();
    }

    /**
     * Lots encore disponibles pour un ingrédient, triés FEFO :
     * le lot qui expire le plus tôt est consommé en premier, les lots sans date à la fin.
     *
     * @return list<StockItem>
     */
    public function findAvailableByIngredientKey(Household $household, string $ingredientKey): array
    {
        $qb = $this->baseQuery($household, false)
            ->andWhere('p.ingredientKey = :key')
            ->setParameter('key', $ingredientKey);

        return $this->applyFefoOrder($qb)->getQuery()->getResult();
    }

    /** @return list<StockItem> */
    public function findAvailable(Household $household): array
    {
        return $this->applyFefoOrder($this->baseQuery($household, false))->getQuery()->getResult();
    }

    /** @return list<string> */
    public function findUsedCategories(Household $household): array
    {
        /** @var list<array{category: string|null}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('DISTINCT p.category AS category')
            ->join('s.product', 'p')
            ->andWhere('s.household = :household')
            ->andWhere('p.category IS NOT NULL')
            ->setParameter('household', $household->getId(), 'uuid')
            ->orderBy('p.category', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_values(array_filter(array_column($rows, 'category')));
    }

    private function baseQuery(Household $household, bool $includeEmpty): QueryBuilder
    {
        $qb = $this->createQueryBuilder('s')
            ->addSelect('p')
            ->join('s.product', 'p')
            ->andWhere('s.household = :household')
            ->setParameter('household', $household->getId(), 'uuid');

        if (!$includeEmpty) {
            $qb->andWhere('s.remainingQuantity > 0');
        }

        return $qb;
    }

    /** DQL n'expose pas NULLS LAST : on trie d'abord sur « a une date ou non ». */
    private function applyFefoOrder(QueryBuilder $qb): QueryBuilder
    {
        return $qb
            ->addSelect('CASE WHEN s.expirationDate IS NULL THEN 1 ELSE 0 END AS HIDDEN hasNoDate')
            ->orderBy('hasNoDate', 'ASC')
            ->addOrderBy('s.expirationDate', 'ASC')
            ->addOrderBy('s.createdAt', 'ASC');
    }
}
