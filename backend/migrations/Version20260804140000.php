<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La découpe : quatrième axe de l'identité culinaire d'un aliment.
 *
 * Les lignes existantes reçoivent `NULL`, c'est-à-dire « découpe inconnue » — et
 * non « entière ». Ce choix n'est pas cosmétique : `VariantIdentity::hash()`
 * n'ajoute son segment que lorsque la découpe est connue, si bien qu'une variante
 * enregistrée avant cet axe conserve **exactement** l'empreinte qu'elle avait.
 * Aucune ligne ne se scinde, aucun `identity_hash` n'est à recalculer ici.
 *
 * La reprise est faite ensuite par `app:variants:detect-cut`, qui déduit la
 * découpe des libellés, absorbe l'ancien trait `sliced`, et se passe d'abord en
 * `--dry-run`.
 */
final class Version20260804140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute household_product_variant.cut et cut_origin';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE household_product_variant ADD cut VARCHAR(16) DEFAULT NULL');
        $this->addSql("ALTER TABLE household_product_variant ADD cut_origin VARCHAR(16) DEFAULT 'detected' NOT NULL");
        $this->addSql('ALTER TABLE household_product_variant ALTER cut_origin DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE household_product_variant DROP cut');
        $this->addSql('ALTER TABLE household_product_variant DROP cut_origin');
    }
}
