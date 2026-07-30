<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Date à laquelle un lot a été passé au congélateur. */
final class Version20260801100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la date de congélation (frozen_at) des lots';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_item ADD frozen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_item DROP frozen_at');
    }
}
