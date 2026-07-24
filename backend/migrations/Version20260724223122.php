<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260724223122 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE app_user (id UUID NOT NULL, email VARCHAR(180) NOT NULL, name VARCHAR(120) NOT NULL, password VARCHAR(255) NOT NULL, roles JSON NOT NULL, dietary_exclusions JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, household_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_88BDF3E9E7927C74 ON app_user (email)');
        $this->addSql('CREATE INDEX IDX_88BDF3E9E79FF843 ON app_user (household_id)');
        $this->addSql('CREATE TABLE household (id UUID NOT NULL, name VARCHAR(120) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE product (id UUID NOT NULL, barcode VARCHAR(64) DEFAULT NULL, name VARCHAR(255) NOT NULL, ingredient_key VARCHAR(255) NOT NULL, brand VARCHAR(180) DEFAULT NULL, category VARCHAR(120) DEFAULT NULL, package_quantity DOUBLE PRECISION DEFAULT NULL, package_unit VARCHAR(16) DEFAULT NULL, image_url VARCHAR(500) DEFAULT NULL, source VARCHAR(32) NOT NULL, refreshed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D34A04AD97AE0266 ON product (barcode)');
        $this->addSql('CREATE INDEX idx_product_ingredient_key ON product (ingredient_key)');
        $this->addSql('CREATE TABLE stock_item (id UUID NOT NULL, initial_quantity DOUBLE PRECISION NOT NULL, remaining_quantity DOUBLE PRECISION NOT NULL, unit VARCHAR(16) NOT NULL, number_of_packages INT NOT NULL, storage_location VARCHAR(32) NOT NULL, purchase_date DATE DEFAULT NULL, expiration_date DATE DEFAULT NULL, opened_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, quantity_is_estimated BOOLEAN NOT NULL, note TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, household_id UUID NOT NULL, product_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6017DDAE79FF843 ON stock_item (household_id)');
        $this->addSql('CREATE INDEX IDX_6017DDA4584665A ON stock_item (product_id)');
        $this->addSql('CREATE INDEX idx_stock_household_expiration ON stock_item (household_id, expiration_date)');
        $this->addSql('CREATE TABLE stock_movement (id UUID NOT NULL, type VARCHAR(32) NOT NULL, quantity DOUBLE PRECISION NOT NULL, unit VARCHAR(16) NOT NULL, remaining_after DOUBLE PRECISION NOT NULL, reason VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, stock_item_id UUID NOT NULL, created_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_BB1BC1B5BC942FD ON stock_movement (stock_item_id)');
        $this->addSql('CREATE INDEX IDX_BB1BC1B5B03A8386 ON stock_movement (created_by_id)');
        $this->addSql('CREATE INDEX idx_movement_item_date ON stock_movement (stock_item_id, created_at)');
        $this->addSql('ALTER TABLE app_user ADD CONSTRAINT FK_88BDF3E9E79FF843 FOREIGN KEY (household_id) REFERENCES household (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE stock_item ADD CONSTRAINT FK_6017DDAE79FF843 FOREIGN KEY (household_id) REFERENCES household (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE stock_item ADD CONSTRAINT FK_6017DDA4584665A FOREIGN KEY (product_id) REFERENCES product (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE stock_movement ADD CONSTRAINT FK_BB1BC1B5BC942FD FOREIGN KEY (stock_item_id) REFERENCES stock_item (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE stock_movement ADD CONSTRAINT FK_BB1BC1B5B03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE app_user DROP CONSTRAINT FK_88BDF3E9E79FF843');
        $this->addSql('ALTER TABLE stock_item DROP CONSTRAINT FK_6017DDAE79FF843');
        $this->addSql('ALTER TABLE stock_item DROP CONSTRAINT FK_6017DDA4584665A');
        $this->addSql('ALTER TABLE stock_movement DROP CONSTRAINT FK_BB1BC1B5BC942FD');
        $this->addSql('ALTER TABLE stock_movement DROP CONSTRAINT FK_BB1BC1B5B03A8386');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE household');
        $this->addSql('DROP TABLE product');
        $this->addSql('DROP TABLE stock_item');
        $this->addSql('DROP TABLE stock_movement');
    }
}
