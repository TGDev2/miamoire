<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Ce que partagent tous les tests qui touchent une vraie base.
 *
 * L'ordre de purge est le point délicat : il suit les clés étrangères, et une
 * table oubliée fait échouer un test sans rapport, plusieurs classes plus loin.
 * Recopier cette liste dans chaque socle de test aurait donc créé deux vérités
 * destinées à diverger — c'est pour cela qu'elle vit ici et nulle part ailleurs.
 */
trait RequiresDatabase
{
    /** Ordre de purge : les dépendantes d'abord, sinon les clés étrangères refusent. */
    private const TABLES = [
        'stock_movement',
        'stock_operation',
        'saved_recipe',
        'stock_item',
        'shopping_list_item',
        'receipt_alias',
        'receipt_capture',
        'household_product_variant',
        'household_member',
        'household',
        'product',
    ];

    /**
     * Ignore le test plutôt que de le faire échouer sur une machine sans Docker.
     *
     *     php bin/console doctrine:database:create --env=test
     *     php bin/console doctrine:migrations:migrate --env=test
     */
    protected function skipUnlessDatabaseIsReachable(EntityManagerInterface $entityManager): void
    {
        try {
            $entityManager->getConnection()->executeQuery('SELECT 1');
        } catch (\Throwable $exception) {
            self::markTestSkipped(sprintf(
                'Base de test injoignable (%s). Créez-la avec doctrine:database:create --env=test.',
                $exception->getMessage(),
            ));
        }
    }

    protected function purgeDatabase(EntityManagerInterface $entityManager): void
    {
        $connection = $entityManager->getConnection();

        foreach (self::TABLES as $table) {
            $connection->executeStatement(sprintf('DELETE FROM %s', $table));
        }
    }
}
