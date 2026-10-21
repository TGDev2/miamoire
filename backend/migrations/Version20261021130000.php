<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** The receipts a household had read, to recognise one read twice. */
final class Version20261021130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the receipt_capture table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE receipt_capture (
              id UUID NOT NULL,
              fingerprint VARCHAR(64) NOT NULL,
              store VARCHAR(120) DEFAULT NULL,
              receipt_date DATE DEFAULT NULL,
              total DOUBLE PRECISION DEFAULT NULL,
              line_count INT NOT NULL,
              read_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              household_id UUID NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_98B4031BE79FF843 ON receipt_capture (household_id)');
        $this->addSql('CREATE INDEX idx_receipt_capture_fingerprint ON receipt_capture (household_id, fingerprint)');
        $this->addSql(<<<'SQL'
            ALTER TABLE receipt_capture
            ADD CONSTRAINT FK_98B4031BE79FF843 FOREIGN KEY (household_id) REFERENCES household (id) ON DELETE CASCADE NOT DEFERRABLE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE receipt_capture DROP CONSTRAINT FK_98B4031BE79FF843');
        $this->addSql('DROP TABLE receipt_capture');
    }
}
