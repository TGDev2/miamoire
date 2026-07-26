<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\StockItem;
use App\Enum\StorageLocation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

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
     *     includeEmpty?: bool,
     *     archived?: bool|null
     * } $filters `archived` : false = inventaire courant, true = archives, null = les deux
     *
     * @return list<StockItem>
     */
    public function search(Household $household, array $filters = []): array
    {
        $archived = \array_key_exists('archived', $filters) ? $filters['archived'] : false;

        // Archiver ramène la quantité restante à zéro : demander les archives tout
        // en filtrant les lots vides ne renverrait, par construction, jamais rien.
        $includeEmpty = ($filters['includeEmpty'] ?? false) || false !== $archived;

        $qb = $this->baseQuery($household, $includeEmpty, $archived);

        if (($filters['location'] ?? null) instanceof StorageLocation) {
            $qb->andWhere('s.storageLocation = :location')
                ->setParameter('location', $filters['location']);
        }

        if (null !== ($filters['category'] ?? null) && '' !== $filters['category']) {
            $qb->andWhere('v.category = :category')
                ->setParameter('category', $filters['category']);
        }

        if (null !== ($filters['search'] ?? null) && '' !== $filters['search']) {
            // Parenthèses explicites : sans elles le OR absorberait les filtres précédents.
            $qb->andWhere('(LOWER(v.name) LIKE :search OR LOWER(v.brand) LIKE :search OR v.ingredientKey LIKE :search)')
                ->setParameter('search', '%'.mb_strtolower($filters['search']).'%');
        }

        if (null !== ($filters['expiringWithinDays'] ?? null)) {
            $qb->andWhere('s.expirationDate IS NOT NULL AND s.expirationDate <= :limitDate')
                ->setParameter('limitDate', new \DateTimeImmutable(sprintf('today +%d days', $filters['expiringWithinDays'])));
        }

        return $this->applyFefoOrder($qb)->getQuery()->getResult();
    }

    /**
     * Lots encore disponibles pour une variante, triés FEFO :
     * le lot qui expire le plus tôt est consommé en premier, les lots sans date à la fin.
     *
     * L'allocation se fait à l'intérieur d'une variante et jamais d'une simple clé
     * d'ingrédient : sans quoi une recette demandant des tomates fraîches pourrait
     * puiser dans les tomates en conserve.
     *
     * @return list<StockItem>
     */
    public function findAvailableByVariant(HouseholdProductVariant $variant): array
    {
        $qb = $this->baseQuery($variant->getHousehold(), false, false)
            ->andWhere('s.variant = :variant')
            ->setParameter('variant', $variant->getId(), 'uuid');

        return $this->applyFefoOrder($qb)->getQuery()->getResult();
    }

    /**
     * Verrouille les lots donnés pour la durée de la transaction.
     *
     * Les identifiants sont triés : deux confirmations concurrentes qui touchent
     * les mêmes lots les prennent dans le même ordre et ne peuvent donc pas
     * s'interbloquer. On verrouille lot par lot plutôt qu'avec un `FOR UPDATE`
     * sur la requête entière, qui verrouillerait aussi les variantes jointes.
     *
     * @param list<Uuid> $ids
     */
    public function lockForUpdate(array $ids): void
    {
        $sorted = array_map(static fn (Uuid $id) => (string) $id, $ids);
        sort($sorted);

        foreach ($sorted as $id) {
            $this->getEntityManager()->find(StockItem::class, Uuid::fromString($id), LockMode::PESSIMISTIC_WRITE);
        }
    }

    /** @return list<StockItem> */
    public function findAvailable(Household $household): array
    {
        return $this->applyFefoOrder($this->baseQuery($household, false, false))->getQuery()->getResult();
    }

    /**
     * Les autres lots du même aliment — deux bouteilles, deux dates.
     *
     * @return list<StockItem>
     */
    public function findSiblings(StockItem $item): array
    {
        $qb = $this->baseQuery($item->getHousehold(), false, false)
            ->andWhere('s.variant = :variant')
            ->andWhere('s.id != :self')
            ->setParameter('variant', $item->getVariant()->getId(), 'uuid')
            ->setParameter('self', $item->getId(), 'uuid');

        return $this->applyFefoOrder($qb)->getQuery()->getResult();
    }

    /** @return list<string> */
    public function findUsedCategories(Household $household): array
    {
        /** @var list<array{category: string|null}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('DISTINCT v.category AS category')
            ->join('s.variant', 'v')
            ->andWhere('s.household = :household')
            ->andWhere('s.archivedAt IS NULL')
            ->andWhere('v.category IS NOT NULL')
            ->setParameter('household', $household->getId(), 'uuid')
            ->orderBy('v.category', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_values(array_filter(array_column($rows, 'category')));
    }

    private function baseQuery(Household $household, bool $includeEmpty, ?bool $archived): QueryBuilder
    {
        $qb = $this->createQueryBuilder('s')
            ->addSelect('v')
            ->join('s.variant', 'v')
            ->andWhere('s.household = :household')
            ->setParameter('household', $household->getId(), 'uuid');

        // Un lot archivé n'est plus de l'inventaire : il ne remonte que si on le
        // demande. `null` les prend tous — c'est ce qu'il faut pour compter ce
        // qu'une suppression de foyer effacerait.
        if (null !== $archived) {
            $qb->andWhere($archived ? 's.archivedAt IS NOT NULL' : 's.archivedAt IS NULL');
        }

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
