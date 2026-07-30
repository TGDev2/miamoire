<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Dernière consultation de la fiche détaillée d'un lot. */
final class Version20260801110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la dernière consultation (last_viewed_at) des lots';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_item ADD last_viewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_item DROP last_viewed_at');
    }
}
