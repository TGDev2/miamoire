<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** What a receipt line means in a household, learnt from its confirmations. */
final class Version20261021120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the receipt_alias table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE receipt_alias (
              id UUID NOT NULL,
              store_key VARCHAR(120) NOT NULL,
              label_key VARCHAR(255) NOT NULL,
              raw_label VARCHAR(255) NOT NULL,
              ignored BOOLEAN NOT NULL,
              confirmed_count INT NOT NULL,
              last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              household_id UUID NOT NULL,
              variant_id UUID DEFAULT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_5F4DFA4E79FF843 ON receipt_alias (household_id)');
        $this->addSql('CREATE INDEX IDX_5F4DFA43B69A9AF ON receipt_alias (variant_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_receipt_alias ON receipt_alias (household_id, store_key, label_key)');
        $this->addSql(<<<'SQL'
            ALTER TABLE receipt_alias
            ADD CONSTRAINT FK_RECEIPT_ALIAS_HOUSEHOLD FOREIGN KEY (household_id) REFERENCES household (id) ON DELETE CASCADE NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE receipt_alias
            ADD CONSTRAINT FK_RECEIPT_ALIAS_VARIANT FOREIGN KEY (variant_id) REFERENCES household_product_variant (id) ON DELETE SET NULL NOT DEFERRABLE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE receipt_alias DROP CONSTRAINT FK_RECEIPT_ALIAS_HOUSEHOLD');
        $this->addSql('ALTER TABLE receipt_alias DROP CONSTRAINT FK_RECEIPT_ALIAS_VARIANT');
        $this->addSql('DROP TABLE receipt_alias');
    }
}
