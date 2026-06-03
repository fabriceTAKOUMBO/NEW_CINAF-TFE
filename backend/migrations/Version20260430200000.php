<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase F — Bascule `studio_id` à NOT NULL sur film + serie.
 *
 * IMPORTANT — Pré-requis d'exécution :
 *   1. Les 10 studios fictifs doivent être chargés (doctrine:fixtures:load).
 *   2. La commande `app:catalogue:import-bunny` doit avoir été exécutée
 *      avec succès pour back-filler `studio_id` sur tous les Film/Serie.
 *   3. Vérifier qu'aucune ligne n'a `studio_id IS NULL` :
 *        SELECT count(*) FROM film  WHERE studio_id IS NULL;  -- doit être 0
 *        SELECT count(*) FROM serie WHERE studio_id IS NULL;  -- doit être 0
 *
 * Sinon la migration échouera avec une violation de contrainte NOT NULL.
 *
 * Cette migration finalise la cardinalité de la relation Studio↔Film/Serie
 * définie en Phase A : tout contenu publié appartient à un studio identifié.
 */
final class Version20260430200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase F — film.studio_id et serie.studio_id deviennent NOT NULL après l\'import Bunny.';
    }

    public function up(Schema $schema): void
    {
        // Garde-fou : la migration échoue proprement si des lignes orphelines
        // restent. Plus explicite que l'erreur PostgreSQL par défaut.
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE
                orphan_count INTEGER;
            BEGIN
                SELECT count(*) INTO orphan_count FROM film WHERE studio_id IS NULL;
                IF orphan_count > 0 THEN
                    RAISE EXCEPTION 'Migration aborted: % film row(s) have studio_id IS NULL. Run app:catalogue:import-bunny first.', orphan_count;
                END IF;
                SELECT count(*) INTO orphan_count FROM serie WHERE studio_id IS NULL;
                IF orphan_count > 0 THEN
                    RAISE EXCEPTION 'Migration aborted: % serie row(s) have studio_id IS NULL. Run app:catalogue:import-bunny first.', orphan_count;
                END IF;
            END $$;
        SQL);

        $this->addSql('ALTER TABLE film ALTER COLUMN studio_id SET NOT NULL');
        $this->addSql('ALTER TABLE serie ALTER COLUMN studio_id SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE film ALTER COLUMN studio_id DROP NOT NULL');
        $this->addSql('ALTER TABLE serie ALTER COLUMN studio_id DROP NOT NULL');
    }
}
