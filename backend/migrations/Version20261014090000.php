<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** When a lot last came out of the freezer. */
final class Version20261014090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the thawing date (thawed_at) of stock items';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_item ADD thawed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_item DROP thawed_at');
    }
}
