<?php

declare(strict_types=1);

namespace App\Tests\Service\Health;

use App\Service\Health\DatabaseHealthChecker;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class DatabaseHealthCheckerTest extends TestCase
{
    public function testReachableWhenTheQuerySucceeds(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeQuery')->with('SELECT 1');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        self::assertTrue((new DatabaseHealthChecker($entityManager))->isReachable());
    }

    /**
     * Vérification par mutation (leçon nº 16) : sans le `catch`, cette exception
     * remonterait jusqu'au contrôleur et ferait planter la sonde au lieu de la
     * faire répondre « base injoignable ».
     */
    public function testUnreachableWhenTheConnectionThrows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willThrowException(new \RuntimeException('connexion refusée'));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        self::assertFalse((new DatabaseHealthChecker($entityManager))->isReachable());
    }
}
