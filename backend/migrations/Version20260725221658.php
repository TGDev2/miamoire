<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute l'état réel des produits : en conserve, épluché, surgelé, précuit…
 *
 * La colonne est créée AVEC une valeur par défaut, pour que les produits déjà
 * enregistrés reçoivent un tableau vide plutôt que NULL, puis le défaut est retiré :
 * la colonne correspond alors exactement au mapping Doctrine, qui n'en déclare aucun.
 * Le même défaut oublié sur `household.dietary_exclusions` est nettoyé au passage —
 * il désynchronisait `doctrine:schema:validate` depuis la migration précédente.
 *
 * Les produits existants restent sans état. `app:products:detect-traits` les
 * complète à partir de leur libellé.
 */
final class Version20260725221658 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la colonne product.traits (état réel du produit)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE product ADD traits JSON DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE product ALTER traits DROP DEFAULT');

        $this->addSql('ALTER TABLE household ALTER dietary_exclusions DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP traits');

        $this->addSql("ALTER TABLE household ALTER dietary_exclusions SET DEFAULT '[]'");
    }
}
