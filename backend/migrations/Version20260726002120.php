<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Donne une serrure à chaque foyer, avant d'exposer l'API sur Internet.
 *
 * La colonne reste nullable et la migration ne génère aucun code : un code créé
 * ici serait écrit haché, donc perdu pour toujours — c'est au moment de sa
 * délivrance, et à ce moment seulement, qu'il est lisible. `RequestContext`
 * refuse un foyer sans code ; `app:households:issue-keys` les délivre et les
 * affiche.
 */
final class Version20260726002120 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le code d\'accès des foyers (empreinte SHA-256)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE household ADD access_key_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_54C32FC03AAA7840 ON household (access_key_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_54C32FC03AAA7840');
        $this->addSql('ALTER TABLE household DROP access_key_hash');
    }
}
