<?php
namespace App\Service;

use App\Entity\SubscriptionPlan;
use App\Entity\User;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Event as StripeEvent;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Wrapper léger autour de stripe-php. Centralise la création de Checkout
 * Sessions et la vérification de signature webhook. Le service est instancié
 * inconditionnellement, mais lève si on l'appelle alors que STRIPE_ENABLED=false
 * (garde-fou pour ne jamais frapper Stripe en mode mock/test).
 */
class StripeService
{
    private ?StripeClient $client = null;

    public function __construct(
        private readonly string $secretKey,
        private readonly string $webhookSecret,
        private readonly string $successUrl,
        private readonly string $cancelUrl,
        private readonly bool $enabled,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Crée une Checkout Session Stripe en mode `embedded` : retourne un
     * `clientSecret` que le frontend passe à `<EmbeddedCheckoutProvider>` pour
     * afficher le formulaire de paiement Stripe directement dans la page,
     * sans redirection vers stripe.com.
     *
     * Le `customer_email` est pré-rempli depuis l'utilisateur courant. Si
     * l'utilisateur a déjà un stripeCustomerId d'un abonnement précédent, on
     * l'utilise pour conserver l'historique côté Stripe.
     *
     * @return array{id:string, clientSecret:string}
     */
    public function createCheckoutSession(User $user, SubscriptionPlan $plan, ?string $existingCustomerId = null): array
    {
        $this->assertEnabled();
        if ($plan->getStripePriceId() === null) {
            throw new \LogicException(sprintf('Le plan "%s" n\'a pas de stripePriceId configuré.', $plan->getName()));
        }

        $params = [
            // Stripe a déprécié `ui_mode=embedded` au profit de `embedded_page` (mai 2026).
            // Le composant frontend `<EmbeddedCheckoutProvider>` continue d'accepter le `clientSecret`
            // produit par cette session, le rendu est inchangé.
            'ui_mode' => 'embedded_page',
            'mode' => 'subscription',
            'line_items' => [[
                'price' => $plan->getStripePriceId(),
                'quantity' => 1,
            ]],
            // En mode embedded, return_url remplace success_url/cancel_url.
            // Stripe substitue {CHECKOUT_SESSION_ID} par l'id réel.
            'return_url' => $this->successUrl,
            // Métadonnées propagées au webhook pour rattacher la session à user+plan en DB.
            'metadata' => [
                'user_id' => $user->getId()->toRfc4122(),
                'plan_id' => $plan->getId()->toRfc4122(),
            ],
            'subscription_data' => [
                'metadata' => [
                    'user_id' => $user->getId()->toRfc4122(),
                    'plan_id' => $plan->getId()->toRfc4122(),
                ],
            ],
        ];

        if ($existingCustomerId !== null) {
            $params['customer'] = $existingCustomerId;
        } else {
            $params['customer_email'] = $user->getEmail();
        }

        /** @var CheckoutSession $session */
        $session = $this->getClient()->checkout->sessions->create($params);

        return [
            'id' => $session->id,
            'clientSecret' => $session->client_secret,
        ];
    }

    /**
     * Récupère le statut d'une Checkout Session par son id — utilisé par la
     * page de retour pour distinguer paid/unpaid sans attendre le webhook.
     */
    public function retrieveCheckoutSession(string $sessionId): CheckoutSession
    {
        $this->assertEnabled();
        return $this->getClient()->checkout->sessions->retrieve($sessionId);
    }

    /**
     * Vérifie la signature du webhook + parse l'event. Lève
     * `\Stripe\Exception\SignatureVerificationException` si invalide.
     */
    public function constructWebhookEvent(string $payload, string $sigHeader): StripeEvent
    {
        $this->assertEnabled();
        return Webhook::constructEvent($payload, $sigHeader, $this->webhookSecret);
    }

    /**
     * Récupère une subscription Stripe par son ID (utilisé par le webhook
     * `checkout.session.completed` pour lire `current_period_end`).
     */
    public function retrieveSubscription(string $stripeSubscriptionId): \Stripe\Subscription
    {
        $this->assertEnabled();
        return $this->getClient()->subscriptions->retrieve($stripeSubscriptionId);
    }

    /**
     * Demande à Stripe d'annuler l'abonnement à la fin de la période payée
     * (`cancel_at_period_end: true`). Stripe continue de facturer jusqu'à
     * l'échéance puis émet `customer.subscription.deleted`. C'est la primitive
     * de la « résiliation différée » côté CINAF v2.
     */
    public function cancelAtPeriodEnd(string $stripeSubscriptionId): \Stripe\Subscription
    {
        $this->assertEnabled();
        return $this->getClient()->subscriptions->update(
            $stripeSubscriptionId,
            ['cancel_at_period_end' => true],
        );
    }

    /**
     * Annule la résiliation différée précédemment demandée
     * (`cancel_at_period_end: false`). Tant que la période payée n'est pas
     * écoulée, l'abonnement repart en renouvellement normal côté Stripe.
     */
    public function resumeAtPeriodEnd(string $stripeSubscriptionId): \Stripe\Subscription
    {
        $this->assertEnabled();
        return $this->getClient()->subscriptions->update(
            $stripeSubscriptionId,
            ['cancel_at_period_end' => false],
        );
    }

    /**
     * Liste les invoices Stripe d'un customer et les transforme en DTOs
     * sérialisables (montants en cents, dates ATOM, status brut Stripe).
     * Utilisé par les endpoints d'historique de paiements.
     *
     * @return list<array{
     *   id:string,
     *   paidAt:?string,
     *   amount:int,
     *   currency:string,
     *   status:?string,
     *   planName:?string,
     *   invoicePdfUrl:?string
     * }>
     */
    public function listInvoices(string $customerId, int $limit = 50): array
    {
        $this->assertEnabled();
        // L'API Stripe attend un limit ∈ [1,100]. On clamp pour éviter une erreur 400.
        $limit = max(1, min(100, $limit));

        $invoices = $this->getClient()->invoices->all([
            'customer' => $customerId,
            'limit' => $limit,
        ]);

        $result = [];
        foreach ($invoices->data as $invoice) {
            $result[] = $this->mapInvoiceToDto($invoice);
        }
        return $result;
    }

    /**
     * Sérialise une `\Stripe\Invoice` au format DTO de l'API CINAF.
     * Le `planName` est résolu best-effort à partir des line items
     * (lookup_key, metadata.plan_name ou description) ; null si introuvable.
     */
    private function mapInvoiceToDto(\Stripe\Invoice $invoice): array
    {
        $paidAtTs = $invoice->status_transitions->paid_at ?? null;
        $paidAt = $paidAtTs !== null
            ? (new \DateTimeImmutable('@' . $paidAtTs))->format(\DateTimeInterface::ATOM)
            : null;

        return [
            'id' => $invoice->id,
            'paidAt' => $paidAt,
            'amount' => $invoice->amount_paid ?? 0,
            'currency' => strtoupper((string) ($invoice->currency ?? 'EUR')),
            'status' => $invoice->status,
            'planName' => $this->resolvePlanName($invoice),
            'invoicePdfUrl' => $invoice->invoice_pdf,
        ];
    }

    /**
     * Tente de retrouver un nom de plan lisible depuis les line items de l'invoice.
     * Ordre de préférence : metadata.plan_name → price.lookup_key → description ligne → null.
     */
    private function resolvePlanName(\Stripe\Invoice $invoice): ?string
    {
        $meta = $invoice->metadata;
        if ($meta !== null) {
            $metaArray = method_exists($meta, 'toArray') ? $meta->toArray() : (array) $meta;
            if (!empty($metaArray['plan_name'])) {
                return (string) $metaArray['plan_name'];
            }
        }

        $lines = $invoice->lines->data ?? [];
        foreach ($lines as $line) {
            $price = $line->price ?? null;
            if ($price !== null && !empty($price->lookup_key)) {
                return (string) $price->lookup_key;
            }
            if (!empty($line->description)) {
                return (string) $line->description;
            }
        }

        return null;
    }

    private function getClient(): StripeClient
    {
        if ($this->client === null) {
            $this->client = new StripeClient($this->secretKey);
        }
        return $this->client;
    }

    private function assertEnabled(): void
    {
        if (!$this->enabled) {
            throw new \LogicException('StripeService appelé alors que STRIPE_ENABLED=false.');
        }
        if ($this->secretKey === '') {
            throw new \LogicException('STRIPE_SECRET_KEY est vide ; impossible de contacter Stripe.');
        }
    }
}
