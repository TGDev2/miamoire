<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<HouseholdProductVariant> */
class HouseholdProductVariantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HouseholdProductVariant::class);
    }

    public function findOneByIdentity(Household $household, string $identityHash): ?HouseholdProductVariant
    {
        return $this->findOneBy(['household' => $household, 'identityHash' => $identityHash]);
    }

    /**
     * La variante que ce foyer a déjà associée à ce produit du catalogue, si
     * elle existe — utilisé pour reconnaître un code-barres déjà scanné.
     *
     * Fondé sur le lien `product`, pas sur un recalcul de l'empreinte
     * d'identité à partir du nom catalogue : un foyer qui a renommé sa
     * variante localement casserait ce recalcul (l'empreinte est justement
     * régénérée au renommage), alors que le lien au produit y survit.
     */
    public function findOneByProduct(Household $household, Product $product): ?HouseholdProductVariant
    {
        return $this->findOneBy(['household' => $household, 'product' => $product]);
    }

    /** Le slug est la clé que le générateur de recettes recopie : elle doit résoudre en une lecture. */
    public function findOneBySlug(Household $household, string $slug): ?HouseholdProductVariant
    {
        return $this->findOneBy(['household' => $household, 'slug' => $slug]);
    }

    /**
     * Slugs déjà pris qui commencent par cette base — de quoi choisir le premier
     * suffixe libre sans faire une requête par tentative.
     *
     * @return list<string>
     */
    public function takenSlugs(Household $household, string $base): array
    {
        /** @var list<array{slug: string}> $rows */
        $rows = $this->createQueryBuilder('v')
            ->select('v.slug')
            ->andWhere('v.household = :household')
            ->andWhere('v.slug = :base OR v.slug LIKE :prefix')
            ->setParameter('household', $household->getId(), 'uuid')
            ->setParameter('base', $base)
            ->setParameter('prefix', $base.'-%')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'slug');
    }

    /**
     * Les variantes dont le foyer a désactivé les suggestions du conseiller avant
     * les courses — pour l'écran qui permet de les réactiver.
     *
     * @return list<HouseholdProductVariant>
     */
    public function findMuted(Household $household): array
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.household = :household')
            ->andWhere('v.shoppingAdvisorMutedAt IS NOT NULL')
            ->setParameter('household', $household->getId(), 'uuid')
            ->orderBy('v.shoppingAdvisorMutedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<HouseholdProductVariant> */
    public function findByIngredientKey(Household $household, string $ingredientKey): array
    {
        return $this->findBy(
            ['household' => $household, 'ingredientKey' => $ingredientKey],
            ['createdAt' => 'ASC'],
        );
    }

    /**
     * Les variantes des aliments qu'un foyer possède sous plusieurs formes.
     *
     * C'est exactement ce que l'ancien modèle fusionnait en une seule ligne
     * d'inventaire : le rapport de migration les donne à relire. Une seule requête
     * — la sous-requête désigne les couples ambigus, la requête principale ramène
     * les variantes elles-mêmes.
     *
     * @return list<HouseholdProductVariant>
     */
    public function findAmbiguousVariants(): array
    {
        // `dup` et non `inner` : ce dernier est un mot réservé du DQL.
        $ambiguous = $this->createQueryBuilder('dup')
            ->select('CONCAT(IDENTITY(dup.household), \'|\', dup.ingredientKey)')
            ->groupBy('dup.household', 'dup.ingredientKey')
            ->having('COUNT(dup.id) > 1')
            ->getDQL();

        return $this->createQueryBuilder('v')
            ->addSelect('h')
            ->join('v.household', 'h')
            ->andWhere(sprintf('CONCAT(IDENTITY(v.household), \'|\', v.ingredientKey) IN (%s)', $ambiguous))
            ->orderBy('h.name', 'ASC')
            ->addOrderBy('v.ingredientKey', 'ASC')
            ->addOrderBy('v.slug', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
