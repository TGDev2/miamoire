<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Report d'un lot depuis « Aujourd'hui » : ne pas le reproposer avant cette date. */
final class Version20260730090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le report (remind_after) des lots signalés par « Aujourd\'hui »';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_item ADD remind_after TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_item DROP remind_after');
    }
}
