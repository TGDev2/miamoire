<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\StockItem;
use App\Enum\StorageLocation;
use App\Service\Freshness\EffectiveUseByCalculator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<StockItem> */
class StockItemRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly EffectiveUseByCalculator $effectiveUseBy,
    ) {
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

        return $this->sortForFefo($qb->getQuery()->getResult());
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

        return $this->sortForFefo($qb->getQuery()->getResult());
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
        return $this->sortForFefo($this->baseQuery($household, false, false)->getQuery()->getResult());
    }

    /**
     * L'inventaire courant — ou celui d'un seul emplacement —, verrouillé pour
     * la durée de la transaction en cours.
     *
     * Même règle que `ownedStockItemForUpdate()` : verrouiller AVANT de lire.
     * On ne sélectionne donc que les identifiants, puis chaque lot est chargé
     * par le verrou lui-même — une relecture par requête rendrait les lots
     * apparus entre-temps sans verrou. Un lot vidé, archivé ou sorti de
     * l'emplacement pendant l'attente n'en fait plus partie : il est écarté ici,
     * sur l'état relu. À l'inverse, un lot entré dans l'emplacement pendant
     * l'attente n'a pas été vu, et n'est pas pris.
     *
     * Le filtre d'emplacement de la requête n'est pas qu'une économie : sans
     * lui, vider le frigo verrouillerait aussi tout le placard.
     *
     * @return list<StockItem>
     */
    public function findAvailableForUpdate(Household $household, ?StorageLocation $location = null): array
    {
        $query = $this->baseQuery($household, false, false)->select('s.id');

        if (null !== $location) {
            $query->andWhere('s.storageLocation = :location')->setParameter('location', $location);
        }

        // Une colonne scalaire revient brute, en chaîne : le type `uuid` n'est
        // appliqué qu'à l'hydratation d'une entité.
        $ids = array_map(
            static fn (string $id): Uuid => Uuid::fromString($id),
            $query->getQuery()->getSingleColumnResult(),
        );

        $this->lockForUpdate($ids);

        $items = [];

        foreach ($ids as $id) {
            $item = $this->find($id);

            if (
                null !== $item
                && !$item->isArchived()
                && !$item->isEmpty()
                && (null === $location || $item->getStorageLocation() === $location)
            ) {
                $items[] = $item;
            }
        }

        return $items;
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

        return $this->sortForFefo($qb->getQuery()->getResult());
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

    /**
     * Enregistre une consultation de la fiche détaillée.
     *
     * Volontairement un `UPDATE` DQL en masse plutôt que `$item->setX()` + `flush()` :
     * `StockItem::touch()` (`#[ORM\PreUpdate]`) rafraîchirait `updatedAt` sur une
     * simple lecture, effaçant la fenêtre de grâce de `StockReviewSelector`, et la
     * colonne `#[ORM\Version]` incrémenterait `revision`, invalidant un
     * `expectedRevision` envoyé par le mobile juste après avoir consulté l'écran.
     * Ce chemin contourne le cycle de vie Doctrine pour éviter les deux.
     */
    public function markViewed(Uuid $id, Household $household, \DateTimeImmutable $viewedAt): void
    {
        $this->getEntityManager()->createQueryBuilder()
            ->update(StockItem::class, 's')
            ->set('s.lastViewedAt', ':viewedAt')
            ->where('s.id = :id')
            ->andWhere('s.household = :household')
            ->setParameter('viewedAt', $viewedAt)
            ->setParameter('id', $id, 'uuid')
            ->setParameter('household', $household->getId(), 'uuid')
            ->getQuery()
            ->execute();
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

    /**
     * L'ordre de consommation : échéance effective d'abord, exemplaire entamé
     * ensuite.
     *
     * Triée en PHP plutôt qu'en SQL : l'échéance effective d'un lot entamé
     * dépend d'une durée de conservation après ouverture
     * ({@see EffectiveUseByCalculator}) que DQL ne peut pas calculer sans
     * dupliquer cette logique. Un foyer compte des dizaines de lots, pas des
     * milliers : trier la liste déjà chargée ne coûte rien qui justifie cette
     * duplication.
     *
     * L'entamé départage à échéance égale, il ne passe pas devant : un lot qui
     * périme demain reste servi avant un paquet ouvert qui périme dans un mois —
     * sinon la priorité aux produits ouverts se paierait en produits jetés. À
     * échéance égale, en revanche — trois sachets de pâtes du même achat, ou
     * trois lots sans date — c'est le paquet déjà ouvert (ou refermé) qui part,
     * et lui seul.
     *
     * @param list<StockItem> $items
     *
     * @return list<StockItem>
     */
    private function sortForFefo(array $items): array
    {
        $usable = array_map(
            fn (StockItem $item): array => [$item, $this->effectiveUseBy->compute($item)->date],
            $items,
        );

        usort($usable, static function (array $a, array $b): int {
            /** @var array{0: StockItem, 1: ?\DateTimeImmutable} $a */
            /** @var array{0: StockItem, 1: ?\DateTimeImmutable} $b */
            [$itemA, $dateA] = $a;
            [$itemB, $dateB] = $b;

            if ((null === $dateA) !== (null === $dateB)) {
                return null === $dateA ? 1 : -1;
            }

            if (null !== $dateA && null !== $dateB && $dateA != $dateB) {
                return $dateA <=> $dateB;
            }

            if ($itemA->isOpened() !== $itemB->isOpened()) {
                return $itemA->isOpened() ? -1 : 1;
            }

            return $itemA->getCreatedAt() <=> $itemB->getCreatedAt();
        });

        return array_column($usable, 0);
    }
}
