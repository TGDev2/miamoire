<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Le foyer peut désactiver les suggestions du conseiller avant les courses, variante par variante. */
final class Version20260801130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute household_product_variant.shopping_advisor_muted_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE household_product_variant ADD shopping_advisor_muted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE household_product_variant DROP shopping_advisor_muted_at');
    }
}
