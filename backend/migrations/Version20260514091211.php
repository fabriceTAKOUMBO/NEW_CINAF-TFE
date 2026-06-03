<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Suppression définitive de la notion isPremium :
 * les colonnes `is_premium` disparaissent des entités Film, Serie et Episode.
 * Le gating d'accès au visionnage est désormais purement basé sur
 * `User::hasActiveSubscription` (catalogue ouvert, lecture réservée aux abonnés).
 *
 * Note : les statements `DROP TABLE refresh_tokens` / `DROP SEQUENCE refresh_tokens_id_seq`
 * générés automatiquement par migrations:diff ont été retirés manuellement (gotcha #1
 * BACKEND_MEMORY : l'entité de gesdinet est un mapped-superclass XML non détecté).
 * Les changements parasites sur `studio_id` (NOT NULL → NULL) et `uniq_withdrawal_pending`
 * ont également été écartés car non liés à la suppression de isPremium.
 */
final class Version20260514091211 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Supprime la colonne is_premium des tables film, serie et episode.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE episode DROP is_premium');
        $this->addSql('ALTER TABLE film DROP is_premium');
        $this->addSql('ALTER TABLE serie DROP is_premium');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE episode ADD is_premium BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE film ADD is_premium BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE serie ADD is_premium BOOLEAN NOT NULL DEFAULT false');
    }
}
