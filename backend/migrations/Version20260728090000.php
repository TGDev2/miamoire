<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Refermer un exemplaire entamé » remettait `open_package_quantity` et
 * `opened_at` à `NULL` : un exemplaire refermé sans être fini redevenait
 * indiscernable d'un exemplaire jamais ouvert, et perdait sa priorité FEFO.
 *
 * `package_resealed` sépare enfin les deux : l'exemplaire reste entamé
 * (`open_package_quantity` non nul, `opened_at` intact), seul ce drapeau dit
 * s'il est actuellement ouvert ou refermé.
 *
 * Aucun rattrapage des lots existants n'est nécessaire : sous l'ancienne
 * sémantique, « refermer » effaçait tout, donc toute ligne qui montre encore
 * aujourd'hui un `open_package_quantity` non nul l'est forcément
 * physiquement — ce cas particulier de refermeture-sans-perte n'existait pas
 * avant ce correctif. `DEFAULT FALSE` est donc déjà la valeur correcte pour
 * 100 % des lignes existantes. On ne tente pas, en revanche, de deviner si un
 * lot aujourd'hui scellé a pu être entamé puis refermé par le passé : cette
 * information est réellement perdue, et l'inventer serait pire que de
 * l'ignorer.
 */
final class Version20260728090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Distingue un exemplaire entamé refermé d\'un exemplaire jamais ouvert';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_item ADD package_resealed BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE stock_item ALTER package_resealed DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_item DROP package_resealed');
    }
}
