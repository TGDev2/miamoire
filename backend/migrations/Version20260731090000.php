<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Mémoire des habitudes d'achat, pour préremplir le « Retour de courses ». */
final class Version20260731090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la contenance et l\'emplacement habituels d\'une variante';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE household_product_variant ADD usual_package_size DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE household_product_variant ADD usual_storage_location VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE household_product_variant DROP usual_package_size');
        $this->addSql('ALTER TABLE household_product_variant DROP usual_storage_location');
    }
}
