<?php

declare(strict_types=1);

namespace App\Service\Health;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Un seul aller-retour SQL : c'est la seule façon de savoir si la base répond
 * vraiment, plutôt que de le supposer parce que le conteneur PHP a démarré
 * (leçon nº 10, `tasks/lessons.md`).
 */
class DatabaseHealthChecker
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function isReachable(): bool
    {
        try {
            $this->entityManager->getConnection()->executeQuery('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
