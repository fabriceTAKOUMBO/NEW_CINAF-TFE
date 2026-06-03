<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute la table `studio_subscription` (abonnement gratuit user → studio,
 * sémantique « follow YouTube »).
 *
 * Schéma :
 *  - PK : id UUID
 *  - FK : user_id (ON DELETE CASCADE), studio_id (ON DELETE CASCADE)
 *  - Index : idx_subscription_studio sur studio_id (pour count rapide)
 *  - Unique : (user_id, studio_id) → un user n'est abonné qu'une fois
 *  - subscribed_at TIMESTAMP NOT NULL
 *
 * Notes sur le nettoyage des statements parasites générés par
 * `doctrine:migrations:diff` (cf. CLAUDE.md / BACKEND_MEMORY.md) :
 *  - `DROP SEQUENCE refresh_tokens_id_seq` + `DROP TABLE refresh_tokens` :
 *    retirés — le bundle gesdinet/jwt-refresh-token-bundle déclare la
 *    table via un mapped-superclass XML que `migrations:diff` ne détecte
 *    pas (gotcha #1), donc chaque diff tente de la supprimer à tort.
 *  - `ALTER TABLE film/serie ALTER studio_id DROP NOT NULL` et
 *    `DROP INDEX uniq_withdrawal_pending` : retirés — schema drift
 *    pré-existant sans rapport avec ce patch (gotcha #16, déjà neutralisé
 *    dans les migrations précédentes).
 */
final class Version20260521103414 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la table studio_subscription (follow gratuit user → studio).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE studio_subscription (id UUID NOT NULL, subscribed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, studio_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_A6D667EAA76ED395 ON studio_subscription (user_id)');
        $this->addSql('CREATE INDEX idx_subscription_studio ON studio_subscription (studio_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_studio ON studio_subscription (user_id, studio_id)');
        $this->addSql('ALTER TABLE studio_subscription ADD CONSTRAINT FK_A6D667EAA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE studio_subscription ADD CONSTRAINT FK_A6D667EA446F285F FOREIGN KEY (studio_id) REFERENCES studio (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE studio_subscription DROP CONSTRAINT FK_A6D667EAA76ED395');
        $this->addSql('ALTER TABLE studio_subscription DROP CONSTRAINT FK_A6D667EA446F285F');
        $this->addSql('DROP TABLE studio_subscription');
    }
}
