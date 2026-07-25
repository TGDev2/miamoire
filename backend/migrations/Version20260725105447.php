<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retrait de l'authentification : les comptes deviennent de simples membres du foyer.
 *
 * Les identifiants sont volontairement conservés à l'identique lors de la copie
 * app_user -> household_member : les mouvements de stock déjà enregistrés continuent
 * donc de pointer vers la bonne personne, et l'historique reste attribué.
 */
final class Version20260725105447 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remplace les comptes utilisateurs par des membres de foyer identifiés par leur prénom';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE household_member (id UUID NOT NULL, first_name VARCHAR(80) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, household_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_59EA3F6DE79FF843 ON household_member (household_id)');

        // Reprise des comptes existants, en gardant leur identifiant.
        $this->addSql('INSERT INTO household_member (id, first_name, created_at, household_id) SELECT id, name, created_at, household_id FROM app_user');

        $this->addSql('CREATE UNIQUE INDEX uniq_member_household_name ON household_member (household_id, first_name)');
        $this->addSql('ALTER TABLE household_member ADD CONSTRAINT FK_59EA3F6DE79FF843 FOREIGN KEY (household_id) REFERENCES household (id) ON DELETE CASCADE NOT DEFERRABLE');

        // Les exclusions alimentaires passent du compte au foyer.
        $this->addSql("ALTER TABLE household ADD dietary_exclusions JSON DEFAULT '[]' NOT NULL");
        $this->addSql('UPDATE household h SET dietary_exclusions = COALESCE((SELECT u.dietary_exclusions FROM app_user u WHERE u.household_id = h.id LIMIT 1), \'[]\')');

        // Qui a enregistré le lot, dénormalisé pour éviter une requête par ligne d'inventaire.
        $this->addSql('ALTER TABLE stock_item ADD added_by_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE stock_item ADD CONSTRAINT FK_6017DDA55B127A4 FOREIGN KEY (added_by_id) REFERENCES household_member (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_6017DDA55B127A4 ON stock_item (added_by_id)');
        $this->addSql("UPDATE stock_item si SET added_by_id = (SELECT sm.created_by_id FROM stock_movement sm WHERE sm.stock_item_id = si.id AND sm.type = 'addition' ORDER BY sm.created_at ASC LIMIT 1)");

        // Le journal pointe désormais vers les membres (mêmes identifiants).
        $this->addSql('ALTER TABLE stock_movement DROP CONSTRAINT fk_bb1bc1b5b03a8386');
        $this->addSql('ALTER TABLE stock_movement ADD CONSTRAINT FK_BB1BC1B5B03A8386 FOREIGN KEY (created_by_id) REFERENCES household_member (id) ON DELETE SET NULL NOT DEFERRABLE');

        $this->addSql('ALTER TABLE app_user DROP CONSTRAINT fk_88bdf3e9e79ff843');
        $this->addSql('DROP TABLE app_user');
    }

    public function down(Schema $schema): void
    {
        // Les mots de passe ne sont pas récupérables : la remontée recrée des comptes vides.
        $this->addSql('CREATE TABLE app_user (id UUID NOT NULL, email VARCHAR(180) NOT NULL, name VARCHAR(120) NOT NULL, password VARCHAR(255) NOT NULL, roles JSON NOT NULL, dietary_exclusions JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, household_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql("INSERT INTO app_user (id, email, name, password, roles, dietary_exclusions, created_at, household_id) SELECT id, id || '@local', first_name, '', '[]', '[]', created_at, household_id FROM household_member");
        $this->addSql('CREATE UNIQUE INDEX uniq_88bdf3e9e7927c74 ON app_user (email)');
        $this->addSql('CREATE INDEX idx_88bdf3e9e79ff843 ON app_user (household_id)');
        $this->addSql('ALTER TABLE app_user ADD CONSTRAINT fk_88bdf3e9e79ff843 FOREIGN KEY (household_id) REFERENCES household (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE stock_movement DROP CONSTRAINT FK_BB1BC1B5B03A8386');
        $this->addSql('ALTER TABLE stock_movement ADD CONSTRAINT fk_bb1bc1b5b03a8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE stock_item DROP CONSTRAINT FK_6017DDA55B127A4');
        $this->addSql('DROP INDEX IDX_6017DDA55B127A4');
        $this->addSql('ALTER TABLE stock_item DROP added_by_id');
        $this->addSql('ALTER TABLE household DROP dietary_exclusions');

        $this->addSql('ALTER TABLE household_member DROP CONSTRAINT FK_59EA3F6DE79FF843');
        $this->addSql('DROP TABLE household_member');
    }
}
