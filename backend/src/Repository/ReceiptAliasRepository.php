<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Household;
use App\Entity\ReceiptAlias;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ReceiptAlias> */
class ReceiptAliasRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReceiptAlias::class);
    }

    public function findOne(Household $household, string $storeKey, string $labelKey): ?ReceiptAlias
    {
        return $this->findOneBy(['household' => $household, 'storeKey' => $storeKey, 'labelKey' => $labelKey]);
    }
}
