<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Parcours self-service "Je suis producteur" + workflow d'approbation
 * du premier contenu d'un studio :
 *
 *   - Ajoute la colonne `studio.is_validated` (default false).
 *   - Force `is_validated = true` pour tous les studios pré-existants
 *     (fixtures + import historique) afin de préserver le comportement
 *     courant : seuls les studios créés en self-service à partir de cette
 *     migration démarrent à `false`.
 *
 * La nouvelle valeur `PENDING_APPROVAL` du champ `status` (Film + Serie)
 * tient dans la colonne `VARCHAR(20)` existante — aucune contrainte CHECK
 * ni enum PostgreSQL côté schema, donc rien à faire ici sur ce volet.
 *
 * Note : les statements parasites générés par migrations:diff ont été
 * retirés manuellement (gotcha #1 BACKEND_MEMORY : refresh_tokens issus
 * du mapped-superclass XML de gesdinet ; gotcha #16 : schema drift sur
 * `withdrawal_request.studio_id` / `uniq_withdrawal_pending` et sur
 * `film.studio_id` / `serie.studio_id`, sans rapport avec ce patch).
 */
final class Version20260514114920 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute studio.is_validated et valide les studios pré-existants.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE studio ADD is_validated BOOLEAN DEFAULT false NOT NULL');
        // Tous les studios créés avant cette migration sont considérés validés
        // (les 10 studios fixtures + tout import historique éventuel).
        $this->addSql('UPDATE studio SET is_validated = true');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE studio DROP is_validated');
    }
}
