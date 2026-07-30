<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Le plan de repas court : chaque entrée porte exactement une recette enregistrée, un snapshot, ou un libellé libre. */
final class Version20260801120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée meal_plan_entry (plan de repas court avec réservations souples)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE meal_plan_entry (
              id UUID NOT NULL,
              date DATE NOT NULL,
              slot VARCHAR(16) NOT NULL,
              recipe_snapshot JSON DEFAULT NULL,
              label VARCHAR(200) DEFAULT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              household_id UUID NOT NULL,
              saved_recipe_id UUID DEFAULT NULL,
              added_by_id UUID DEFAULT NULL,
              PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX IDX_EFEB6E06E79FF843 ON meal_plan_entry (household_id)');
        $this->addSql('CREATE INDEX IDX_EFEB6E066C03EBE9 ON meal_plan_entry (saved_recipe_id)');
        $this->addSql('CREATE INDEX IDX_EFEB6E0655B127A4 ON meal_plan_entry (added_by_id)');
        $this->addSql('CREATE INDEX idx_meal_plan_entry_household_date ON meal_plan_entry (household_id, date)');
        $this->addSql(<<<'SQL'
            ALTER TABLE meal_plan_entry
            ADD CONSTRAINT FK_EFEB6E06E79FF843 FOREIGN KEY (household_id) REFERENCES household (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE meal_plan_entry
            ADD CONSTRAINT FK_EFEB6E066C03EBE9 FOREIGN KEY (saved_recipe_id) REFERENCES saved_recipe (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE meal_plan_entry
            ADD CONSTRAINT FK_EFEB6E0655B127A4 FOREIGN KEY (added_by_id) REFERENCES household_member (id) ON DELETE SET NULL NOT DEFERRABLE
            SQL);
        // Exactement un des trois champs de contenu : la base tient l'invariant,
        // pas seulement les fabriques nommées de l'entité (voir MealPlanEntry).
        $this->addSql(<<<'SQL'
            ALTER TABLE meal_plan_entry
            ADD CONSTRAINT chk_meal_plan_entry_content CHECK (
              (CASE WHEN saved_recipe_id IS NOT NULL THEN 1 ELSE 0 END)
              + (CASE WHEN recipe_snapshot IS NOT NULL THEN 1 ELSE 0 END)
              + (CASE WHEN label IS NOT NULL THEN 1 ELSE 0 END) = 1
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meal_plan_entry DROP CONSTRAINT FK_EFEB6E06E79FF843');
        $this->addSql('ALTER TABLE meal_plan_entry DROP CONSTRAINT FK_EFEB6E066C03EBE9');
        $this->addSql('ALTER TABLE meal_plan_entry DROP CONSTRAINT FK_EFEB6E0655B127A4');
        $this->addSql('DROP TABLE meal_plan_entry');
    }
}
