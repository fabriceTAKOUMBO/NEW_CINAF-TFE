<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sprint 1 — table `refresh_tokens` du bundle gesdinet/jwt-refresh-token-bundle
 * (jetons de rafraîchissement de session, valables 30 jours).
 *
 * Écrite à la main : le bundle décrit cette table par une mapped-superclass XML
 * que `doctrine:migrations:diff` ne prend pas en compte. Le diff ne la crée
 * donc jamais et propose au contraire, à chaque nouvelle migration, de la
 * supprimer (`DROP TABLE refresh_tokens`) : ces lignes doivent être retirées à
 * la main (gotcha n° 1 de BACKEND_MEMORY.md).
 *
 * Colonnes : `refresh_token` (chaîne opaque, unique), `username` (email du
 * propriétaire) et `valid` (date d'expiration).
 */
final class Version20260421210400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create refresh_tokens table for gesdinet/jwt-refresh-token-bundle';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE refresh_tokens (
            id SERIAL NOT NULL,
            refresh_token VARCHAR(128) NOT NULL,
            username VARCHAR(255) NOT NULL,
            valid TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9BACE7E1C74F2195 ON refresh_tokens (refresh_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE refresh_tokens');
    }
}
