<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les exemplaires d'un lot, et les recettes que le foyer garde.
 *
 * Deux choses arrivent ici.
 *
 * 1. `stock_item` apprend son conditionnement. `package_size` était jusqu'ici
 *    recalculé à la volée (`initial_quantity / number_of_packages`), donc faux
 *    dès la première correction de quantité ; `open_package_quantity` dit ce
 *    qu'il reste dans l'exemplaire entamé — et remplace le drapeau `opened_at`
 *    comme source de vérité de l'état « entamé ».
 * 2. `saved_recipe` apparaît, avec son unicité par empreinte : enregistrer deux
 *    fois la même recette ne crée pas de doublon, et c'est la base qui le tient.
 *
 * La reprise des lots existants est écrite en SQL pur. Le point délicat est le
 * calcul de `open_package_quantity` : on ne sait pas quel sachet a été ouvert,
 * seulement qu'un l'a été. Le reste modulo la contenance le reconstitue —
 * 1250 g sur des sachets de 500 g, c'est deux sachets scellés et un entamé à
 * 250 g. Le cas limite est le multiple exact : 1500 g doit donner UN sachet
 * ouvert de 500 g et deux scellés, pas trois scellés ; d'où le retrait d'un
 * epsilon avant le `FLOOR`.
 *
 * `down()` est complète. Elle reperd, par construction, la distinction entre
 * exemplaires : les lots redeviennent des totaux, et `opened_at` redevient le
 * seul drapeau. C'est le prix d'un retour arrière, et il vaut mieux qu'il soit
 * écrit ici que découvert un soir de déploiement.
 */
final class Version20260726210412 extends AbstractMigration
{
    /**
     * Ce qu'il reste dans l'exemplaire entamé, reconstitué depuis le total.
     *
     * Terme à terme, c'est le `while` de `StockItem::getPackages()` : on retire
     * autant d'exemplaires pleins que le reste en contient, ce qui subsiste est
     * l'exemplaire ouvert. L'epsilon force un multiple exact à laisser un
     * exemplaire entier ouvert plutôt que zéro.
     */
    private const OPEN_REMAINDER = <<<'SQL'
        ROUND(
            (remaining_quantity - package_size * FLOOR(GREATEST(remaining_quantity - 0.000001, 0) / package_size))::numeric,
            3
        )::double precision
        SQL;

    public function getDescription(): string
    {
        return 'Suivi individuel des exemplaires d\'un lot et recettes enregistrées par foyer';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE saved_recipe (
                id UUID NOT NULL,
                household_id UUID NOT NULL,
                saved_by_id UUID DEFAULT NULL,
                name VARCHAR(200) NOT NULL,
                fingerprint VARCHAR(64) NOT NULL,
                recipe JSON NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        // Index de clé étrangère : les noms sont ceux que Doctrine génère, pour que
        // `doctrine:schema:validate` reste vert et qu'un futur `migrations:diff`
        // ne propose pas de renommages parasites.
        $this->addSql('CREATE INDEX IDX_BE8ECA2CE79FF843 ON saved_recipe (household_id)');
        $this->addSql('CREATE INDEX IDX_BE8ECA2CC734FB1D ON saved_recipe (saved_by_id)');
        $this->addSql('CREATE INDEX idx_saved_recipe_household_created ON saved_recipe (household_id, created_at)');
        // L'unicité est portée par la base : deux téléphones qui enregistrent la
        // même recette au même instant ne peuvent pas la dédoubler.
        $this->addSql('CREATE UNIQUE INDEX uniq_saved_recipe_fingerprint ON saved_recipe (household_id, fingerprint)');
        $this->addSql('ALTER TABLE saved_recipe ADD CONSTRAINT FK_BE8ECA2CE79FF843 FOREIGN KEY (household_id) REFERENCES household (id) ON DELETE CASCADE NOT DEFERRABLE');
        // `household_member`, pas `member` : la table porte déjà son préfixe.
        $this->addSql('ALTER TABLE saved_recipe ADD CONSTRAINT FK_BE8ECA2CC734FB1D FOREIGN KEY (saved_by_id) REFERENCES household_member (id) ON DELETE SET NULL NOT DEFERRABLE');

        // Nullable d'abord : la colonne est obligatoire pour l'entité, mais les
        // lots existants n'ont encore aucune valeur à y mettre.
        $this->addSql('ALTER TABLE stock_item ADD package_size DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE stock_item ADD open_package_quantity DOUBLE PRECISION DEFAULT NULL');

        $this->addSql(<<<'SQL'
            UPDATE stock_item
            SET package_size = CASE
                WHEN number_of_packages > 0 AND initial_quantity > 0 THEN initial_quantity / number_of_packages
                ELSE GREATEST(remaining_quantity, 0)
            END
        SQL);

        // Un lot sans contenance exploitable — quantité initiale nulle, lot vidé
        // puis archivé — reste un exemplaire unique plutôt qu'une division par zéro.
        $this->addSql('UPDATE stock_item SET package_size = GREATEST(remaining_quantity, 0) WHERE package_size IS NULL OR package_size <= 0');
        $this->addSql('ALTER TABLE stock_item ALTER COLUMN package_size SET NOT NULL');

        $remainder = self::OPEN_REMAINDER;
        $this->addSql(<<<SQL
            UPDATE stock_item
            SET open_package_quantity = {$remainder}
            WHERE opened_at IS NOT NULL
              AND remaining_quantity > 0
              AND package_size > 0
        SQL);

        // Lot marqué ouvert dont la contenance est inconnue : tout ce qui reste
        // est dans l'exemplaire entamé, faute de mieux et sans rien inventer.
        $this->addSql(<<<'SQL'
            UPDATE stock_item
            SET open_package_quantity = remaining_quantity
            WHERE opened_at IS NOT NULL
              AND remaining_quantity > 0
              AND (open_package_quantity IS NULL OR open_package_quantity <= 0)
        SQL);

        // Un lot vide ne peut plus être « entamé » : c'est désormais
        // `open_package_quantity` qui fait foi, et les deux doivent s'accorder.
        $this->addSql('UPDATE stock_item SET opened_at = NULL WHERE open_package_quantity IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE saved_recipe DROP CONSTRAINT FK_BE8ECA2CE79FF843');
        $this->addSql('ALTER TABLE saved_recipe DROP CONSTRAINT FK_BE8ECA2CC734FB1D');
        $this->addSql('DROP TABLE saved_recipe');
        $this->addSql('ALTER TABLE stock_item DROP package_size');
        $this->addSql('ALTER TABLE stock_item DROP open_package_quantity');
    }
}
