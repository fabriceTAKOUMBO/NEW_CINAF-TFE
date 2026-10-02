<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Unicité de l'abonnement Stripe côté base.
 *
 * Depuis le 2026-09-26, un paiement Stripe active l'abonnement par deux voies :
 * le retour du client sur la page de succès (`GET /api/subscriptions/session/{id}`)
 * et le webhook `checkout.session.completed`. Les deux vérifient qu'aucune ligne
 * ne porte déjà l'identifiant Stripe avant d'insérer ; s'ils arrivent en même
 * temps, les deux vérifications peuvent passer. Cet index unique garantit
 * qu'un abonnement Stripe ne produit qu'une seule ligne `subscription` : le
 * second insert échoue et SubscriptionService le traite comme « déjà appliqué ».
 *
 * PostgreSQL accepte plusieurs NULL dans un index unique : les abonnements du
 * mode simulé (sans identifiant Stripe) ne sont pas concernés.
 *
 * Migration écrite à la main (cf. CLAUDE.md gotcha #1 : `doctrine:migrations:diff`
 * ajouterait un `DROP TABLE refresh_tokens` parasite). Le nom de l'index est celui
 * déclaré par `#[ORM\UniqueConstraint]` sur l'entité Subscription.
 */
final class Version20260926100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index unique sur subscription.stripe_subscription_id (activation au retour + webhook).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_subscription_stripe_subscription ON subscription (stripe_subscription_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_subscription_stripe_subscription');
    }
}
