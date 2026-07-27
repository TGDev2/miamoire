<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sécurité alimentaire honnête : sévérité des exclusions, composition
 * structurée des produits et version de schéma des recettes enregistrées.
 */
final class Version20260728120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sévérité des exclusions, allergènes structurés des produits et version de schéma des recettes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE product ADD allergens_tags JSON DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE product ALTER allergens_tags DROP DEFAULT');
        $this->addSql("ALTER TABLE product ADD traces_tags JSON DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE product ALTER traces_tags DROP DEFAULT');
        $this->addSql('ALTER TABLE product ADD ingredients_text TEXT DEFAULT NULL');

        // `NULL` — pas de défaut — veut dire « rien déclaré par ce foyer » ; un
        // tableau vide est une déclaration à part entière, voir l'entité.
        $this->addSql('ALTER TABLE household_product_variant ADD allergens_override JSON DEFAULT NULL');

        $this->addSql('ALTER TABLE saved_recipe ADD schema_version SMALLINT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE saved_recipe ALTER schema_version DROP DEFAULT');

        // ["lait", "coriandre"] devient [{"label":"lait","severity":"allergy"}, ...].
        // Défaut sûr pour l'existant : une exclusion dont on ignore la sévérité
        // est traitée en allergie stricte plutôt qu'en simple préférence.
        $this->addSql(<<<'SQL'
            UPDATE household
            SET dietary_exclusions = (
                SELECT COALESCE(jsonb_agg(jsonb_build_object('label', label, 'severity', 'allergy')), '[]'::jsonb)
                FROM jsonb_array_elements_text(dietary_exclusions::jsonb) AS label
            )
            WHERE jsonb_typeof(dietary_exclusions::jsonb) = 'array'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE household
            SET dietary_exclusions = (
                SELECT COALESCE(jsonb_agg(entry->>'label'), '[]'::jsonb)
                FROM jsonb_array_elements(dietary_exclusions::jsonb) AS entry
            )
            WHERE jsonb_typeof(dietary_exclusions::jsonb) = 'array'
            SQL);

        $this->addSql('ALTER TABLE saved_recipe DROP schema_version');
        $this->addSql('ALTER TABLE household_product_variant DROP allergens_override');
        $this->addSql('ALTER TABLE product DROP allergens_tags');
        $this->addSql('ALTER TABLE product DROP traces_tags');
        $this->addSql('ALTER TABLE product DROP ingredients_text');
    }
}
