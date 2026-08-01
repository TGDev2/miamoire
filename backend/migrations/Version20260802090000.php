<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Noms des dernières recettes montrées au foyer, pour éviter de les répéter. */
final class Version20260802090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute household.recent_recipe_suggestions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE household ADD recent_recipe_suggestions JSON DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE household ALTER recent_recipe_suggestions DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE household DROP recent_recipe_suggestions');
    }
}
