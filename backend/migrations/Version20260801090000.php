<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** La liste de courses du foyer. */
final class Version20260801090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée la table shopping_list_item';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE shopping_list_item (
              id UUID NOT NULL,
              label VARCHAR(255) NOT NULL,
              quantity DOUBLE PRECISION DEFAULT NULL,
              unit VARCHAR(16) NOT NULL,
              checked BOOLEAN NOT NULL,
              checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              household_id UUID NOT NULL,
              variant_id UUID DEFAULT NULL,
              added_by_id UUID DEFAULT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_4FB1C224E79FF843 ON shopping_list_item (household_id)');
        $this->addSql('CREATE INDEX IDX_4FB1C2243B69A9AF ON shopping_list_item (variant_id)');
        $this->addSql('CREATE INDEX IDX_4FB1C22455B127A4 ON shopping_list_item (added_by_id)');
        $this->addSql('CREATE INDEX idx_shopping_list_household_checked ON shopping_list_item (household_id, checked)');
        $this->addSql(<<<'SQL'
            ALTER TABLE shopping_list_item
            ADD CONSTRAINT FK_4FB1C224E79FF843 FOREIGN KEY (household_id) REFERENCES household (id) ON DELETE CASCADE NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE shopping_list_item
            ADD CONSTRAINT FK_4FB1C2243B69A9AF FOREIGN KEY (variant_id) REFERENCES household_product_variant (id) ON DELETE SET NULL NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE shopping_list_item
            ADD CONSTRAINT FK_4FB1C22455B127A4 FOREIGN KEY (added_by_id) REFERENCES household_member (id) ON DELETE SET NULL NOT DEFERRABLE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shopping_list_item DROP CONSTRAINT FK_4FB1C224E79FF843');
        $this->addSql('ALTER TABLE shopping_list_item DROP CONSTRAINT FK_4FB1C2243B69A9AF');
        $this->addSql('ALTER TABLE shopping_list_item DROP CONSTRAINT FK_4FB1C22455B127A4');
        $this->addSql('DROP TABLE shopping_list_item');
    }
}
