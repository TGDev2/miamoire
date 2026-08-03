<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Frais ou conditionné : la nature d'un aliment dans un foyer.
 *
 * Les lignes existantes reçoivent `packaged`, qui est exactement le comportement
 * qu'avait l'application avant cette notion — donc aucune régression. Elles sont
 * ensuite reclassées par `app:variants:detect-nature`, qui ne touche à aucun lot.
 */
final class Version20260804090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute household_product_variant.nature et nature_origin';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE household_product_variant ADD nature VARCHAR(16) DEFAULT 'packaged' NOT NULL");
        $this->addSql("ALTER TABLE household_product_variant ADD nature_origin VARCHAR(16) DEFAULT 'detected' NOT NULL");
        $this->addSql('ALTER TABLE household_product_variant ALTER nature DROP DEFAULT');
        $this->addSql('ALTER TABLE household_product_variant ALTER nature_origin DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE household_product_variant DROP nature');
        $this->addSql('ALTER TABLE household_product_variant DROP nature_origin');
    }
}
