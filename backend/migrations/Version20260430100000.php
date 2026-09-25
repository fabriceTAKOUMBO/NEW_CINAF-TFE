<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase A — Producer module foundation:
 * Create the `studio` table.
 *
 * - PK UUID
 * - owner_id UNIQUE FK -> user(id) ON DELETE RESTRICT (one user owns
 *   at most one studio: this is the OneToOne relationship)
 * - unique indexes on name, slug, bunny_folder
 *
 * Note: the auto-generated `DROP TABLE refresh_tokens` / `DROP SEQUENCE`
 * statements (mapped-superclass JWT bundle, see Version20260427095608)
 * have been omitted intentionally.
 *
 * En français : crée la table `studio` (maison de production qui publie des
 * films et des séries). L'index unique sur `owner_id` traduit la relation
 * OneToOne « un utilisateur possède au plus un studio », et ON DELETE RESTRICT
 * empêche de supprimer un utilisateur qui possède encore un studio. `name`,
 * `slug` et `bunny_folder` sont uniques ; le slug sert aussi à construire le
 * dossier Bunny du studio (`studios/{slug}/`). La colonne `is_validated` est
 * ajoutée plus tard (Version20260514114920).
 */
final class Version20260430100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase A — Create studio table (producer module foundation).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE studio (
                id UUID NOT NULL,
                owner_id UUID NOT NULL,
                name VARCHAR(255) NOT NULL,
                slug VARCHAR(280) NOT NULL,
                description TEXT DEFAULT NULL,
                logo_url VARCHAR(500) DEFAULT NULL,
                bunny_folder VARCHAR(255) NOT NULL,
                is_active BOOLEAN DEFAULT true NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);

        $this->addSql('CREATE UNIQUE INDEX UNIQ_4A2B07B65E237E06 ON studio (name)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4A2B07B6989D9B62 ON studio (slug)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4A2B07B650A41F37 ON studio (bunny_folder)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4A2B07B67E3C61F9 ON studio (owner_id)');

        $this->addSql('ALTER TABLE studio ADD CONSTRAINT FK_4A2B07B67E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE RESTRICT NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE studio DROP CONSTRAINT FK_4A2B07B67E3C61F9');
        $this->addSql('DROP TABLE studio');
    }
}
