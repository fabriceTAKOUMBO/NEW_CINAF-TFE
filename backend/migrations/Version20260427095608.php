<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Création des tables `subscription_plan` et `subscription` (Sprint 3 — mock Stripe-ready).
 *
 * Note : les statements `DROP TABLE refresh_tokens` / `DROP SEQUENCE refresh_tokens_id_seq`
 * générés automatiquement ont été retirés (gotcha #1 — mapped-superclass JWT non détecté).
 */
final class Version20260427095608 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sprint 3 — Add subscription and subscription_plan tables (mock Stripe-ready).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE subscription_plan (id UUID NOT NULL, name VARCHAR(100) NOT NULL, description TEXT DEFAULT NULL, price_cents INT NOT NULL, currency VARCHAR(3) NOT NULL, interval_unit VARCHAR(10) NOT NULL, interval_count INT NOT NULL, features JSON NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, stripe_price_id VARCHAR(100) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');

        $this->addSql('CREATE TABLE subscription (id UUID NOT NULL, status VARCHAR(20) NOT NULL, starts_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ends_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, canceled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, stripe_subscription_id VARCHAR(100) DEFAULT NULL, stripe_customer_id VARCHAR(100) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, plan_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_A3C664D3A76ED395 ON subscription (user_id)');
        $this->addSql('CREATE INDEX IDX_A3C664D3E899029B ON subscription (plan_id)');
        $this->addSql('CREATE INDEX idx_subscription_user_status ON subscription (user_id, status)');
        $this->addSql('ALTER TABLE subscription ADD CONSTRAINT FK_A3C664D3A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE subscription ADD CONSTRAINT FK_A3C664D3E899029B FOREIGN KEY (plan_id) REFERENCES subscription_plan (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription DROP CONSTRAINT FK_A3C664D3A76ED395');
        $this->addSql('ALTER TABLE subscription DROP CONSTRAINT FK_A3C664D3E899029B');
        $this->addSql('DROP TABLE subscription');
        $this->addSql('DROP TABLE subscription_plan');
    }
}
