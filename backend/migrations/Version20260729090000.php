<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Catégories qu'un foyer écarte définitivement de « Vérifier mon stock ». */
final class Version20260729090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catégories mises en sourdine pour la vérification rapide du stock';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE household ADD muted_review_categories JSON DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE household ALTER muted_review_categories DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE household DROP muted_review_categories');
    }
}
