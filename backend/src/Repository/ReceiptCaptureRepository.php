<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Household;
use App\Entity\ReceiptCapture;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ReceiptCapture> */
class ReceiptCaptureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReceiptCapture::class);
    }

    /** The most recent earlier read of the same receipt, if any, within the window. */
    public function findEarlierRead(Household $household, string $fingerprint, \DateTimeImmutable $since): ?ReceiptCapture
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.household = :household')
            ->andWhere('c.fingerprint = :fingerprint')
            ->andWhere('c.readAt >= :since')
            ->setParameter('household', $household->getId(), 'uuid')
            ->setParameter('fingerprint', $fingerprint)
            ->setParameter('since', $since)
            ->orderBy('c.readAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
