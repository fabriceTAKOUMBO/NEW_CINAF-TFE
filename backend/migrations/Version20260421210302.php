<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 *
 * Sprint 1 — création de la table `user` (comptes de la plateforme) :
 * identifiant UUID, email unique (index UNIQ_8D93D649E7927C74), rôles stockés
 * en JSON, mot de passe haché, prénom et nom, vérification d'email
 * (`is_verified`, `verification_token`), réinitialisation du mot de passe
 * (jeton + date d'expiration), consentement RGPD et horodatages.
 *
 * `user` est un mot réservé de PostgreSQL : le nom de table est donc toujours
 * écrit entre guillemets. Les colonnes ajoutées plus tard (ex. `is_suspended`)
 * viennent des migrations suivantes.
 */
final class Version20260421210302 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE "user" (id UUID NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, is_verified BOOLEAN NOT NULL, verification_token VARCHAR(255) DEFAULT NULL, password_reset_token VARCHAR(255) DEFAULT NULL, password_reset_token_expiry TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, consent_rgpd BOOLEAN DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE "user"');
    }
}
