<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Deux compteurs optimistes, un par entité qui en avait besoin.
 *
 * `stock_item.revision` est celui que le mobile renvoie en `expectedRevision`
 * pour une correction absolue : le serveur refuse alors d'écraser un
 * changement qu'elle n'a pas vu, au lieu de le faire silencieusement.
 *
 * `stock_operation.version` empêche deux relances concurrentes de la même
 * clé périmée de gagner toutes les deux — Doctrine l'ajoute automatiquement à
 * la clause `WHERE` de l'UPDATE que fait `StockOperation::retry()`.
 *
 * Les lots et opérations existants démarrent à 1, comme tout nouvel enregistrement.
 */
final class Version20260727143014 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Verrouillage optimiste des lots et des opérations de stock';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_item ADD revision INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE stock_operation ADD version INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_item DROP revision');
        $this->addSql('ALTER TABLE stock_operation DROP version');
    }
}
