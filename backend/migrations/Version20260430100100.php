<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase A — Producer module foundation:
 * Add studio_id, status, published_at, withdrawn_at on `film` and `serie`.
 *
 * - studio_id UUID NULL (will be made NOT NULL in Phase F after Agent 5
 *   import pipeline back-fills existing rows)
 * - status VARCHAR(20) NOT NULL DEFAULT 'DRAFT' (DRAFT|PUBLISHED|WITHDRAWN)
 * - published_at TIMESTAMP NULL
 * - withdrawn_at TIMESTAMP NULL
 * - composite index (status, studio_id)
 *
 * En français : rattache chaque film et chaque série à un studio et introduit
 * le cycle de vie du contenu (colonne `status`, DRAFT par défaut, et dates de
 * publication / de retrait). `studio_id` reste nullable ici, le temps que
 * l'import du catalogue le renseigne ; il devient NOT NULL avec
 * Version20260430200000. Le statut PENDING_APPROVAL, ajouté ensuite, tient dans
 * le même VARCHAR(20) sans changement de schéma (cf. Version20260514114920).
 * L'index (status, studio_id) sert les listes filtrées par studio et par statut.
 */
final class Version20260430100100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase A — Add studio_id + status workflow columns on film and serie.';
    }

    public function up(Schema $schema): void
    {
        // -- film -------------------------------------------------------------
        $this->addSql("ALTER TABLE film ADD studio_id UUID DEFAULT NULL");
        $this->addSql("ALTER TABLE film ADD status VARCHAR(20) DEFAULT 'DRAFT' NOT NULL");
        $this->addSql("ALTER TABLE film ADD published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL");
        $this->addSql("ALTER TABLE film ADD withdrawn_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL");
        $this->addSql('ALTER TABLE film ADD CONSTRAINT FK_8244BE22446F285F FOREIGN KEY (studio_id) REFERENCES studio (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_8244BE22446F285F ON film (studio_id)');
        $this->addSql('CREATE INDEX idx_film_status_studio ON film (status, studio_id)');

        // -- serie ------------------------------------------------------------
        $this->addSql("ALTER TABLE serie ADD studio_id UUID DEFAULT NULL");
        $this->addSql("ALTER TABLE serie ADD status VARCHAR(20) DEFAULT 'DRAFT' NOT NULL");
        $this->addSql("ALTER TABLE serie ADD published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL");
        $this->addSql("ALTER TABLE serie ADD withdrawn_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL");
        $this->addSql('ALTER TABLE serie ADD CONSTRAINT FK_AA3A9334446F285F FOREIGN KEY (studio_id) REFERENCES studio (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_AA3A9334446F285F ON serie (studio_id)');
        $this->addSql('CREATE INDEX idx_serie_status_studio ON serie (status, studio_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_serie_status_studio');
        $this->addSql('DROP INDEX IDX_AA3A9334446F285F');
        $this->addSql('ALTER TABLE serie DROP CONSTRAINT FK_AA3A9334446F285F');
        $this->addSql('ALTER TABLE serie DROP withdrawn_at');
        $this->addSql('ALTER TABLE serie DROP published_at');
        $this->addSql('ALTER TABLE serie DROP status');
        $this->addSql('ALTER TABLE serie DROP studio_id');

        $this->addSql('DROP INDEX idx_film_status_studio');
        $this->addSql('DROP INDEX IDX_8244BE22446F285F');
        $this->addSql('ALTER TABLE film DROP CONSTRAINT FK_8244BE22446F285F');
        $this->addSql('ALTER TABLE film DROP withdrawn_at');
        $this->addSql('ALTER TABLE film DROP published_at');
        $this->addSql('ALTER TABLE film DROP status');
        $this->addSql('ALTER TABLE film DROP studio_id');
    }
}
