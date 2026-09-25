<?php
namespace App\Controller;

use App\Service\StripeService;
use App\Service\SubscriptionService;
use Psr\Log\LoggerInterface;
use Stripe\Exception\SignatureVerificationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoint webhook Stripe : `POST /api/stripe/webhook` (PUBLIC_ACCESS dans
 * security.yaml). La signature HMAC est vérifiée par `StripeService` ; un
 * payload sans signature valide reçoit 400 sans toucher la DB.
 *
 * Idempotence : déléguée à SubscriptionService (chaque event peut être rejoué
 * par Stripe — at-least-once delivery).
 *
 * C'est le seul point d'entrée par lequel un paiement Stripe crée ou modifie
 * un abonnement en base. Événements traités par
 * SubscriptionService::syncFromStripeEvent() :
 *  - `checkout.session.completed`    → création de l'abonnement ACTIVE ;
 *  - `customer.subscription.updated` → renouvellement, résiliation différée ou réactivation ;
 *  - `customer.subscription.deleted` → fin de l'abonnement (EXPIRED).
 * Les autres types sont acquittés sans effet (`applied: false`).
 */
class StripeWebhookController extends AbstractController
{
    public function __construct(
        private readonly StripeService $stripe,
        private readonly SubscriptionService $subService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Reçoit un événement Stripe, vérifie sa signature puis synchronise la base.
     *
     * Aucun JWT ici : l'appelant est le serveur Stripe. L'authenticité repose
     * sur l'en-tête `Stripe-Signature`, un HMAC (horodatage + corps brut) calculé
     * avec le secret STRIPE_WEBHOOK_SECRET ; la bibliothèque stripe-php refuse
     * aussi un horodatage écarté de plus de 5 minutes (protection anti-rejeu).
     *
     * @return JsonResponse 200 `{received: true, applied: bool}` si la signature est valide ;
     *                      400 signature ou JSON invalide ; 503 si Stripe est désactivé
     */
    #[Route('/api/stripe/webhook', methods: ['POST'])]
    public function handle(Request $request): JsonResponse
    {
        if (!$this->stripe->isEnabled()) {
            // Si STRIPE_ENABLED=false, on rejette les hits webhook (anti-bruit).
            return $this->json(['message' => 'Stripe est désactivé sur cette instance.'], 503);
        }

        // Le corps doit rester brut (non décodé) : la signature porte sur les octets exacts reçus.
        $payload = $request->getContent();
        $sigHeader = (string) $request->headers->get('Stripe-Signature', '');

        try {
            $event = $this->stripe->constructWebhookEvent($payload, $sigHeader);
        } catch (\UnexpectedValueException | SignatureVerificationException $e) {
            $this->logger->warning('Stripe webhook signature invalide.', ['error' => $e->getMessage()]);
            return $this->json(['message' => 'Signature invalide.'], 400);
        }

        $applied = $this->subService->syncFromStripeEvent($event);
        $this->logger->info('Stripe webhook reçu.', ['type' => $event->type, 'applied' => $applied]);

        // 200 systématique sur signature valide : Stripe re-essaie sur 5xx.
        // (Stripe renvoie en fait tout événement non acquitté par un 2xx ; un
        // événement ignoré doit donc quand même recevoir 200. Une exception levée
        // pendant la synchronisation produit un 500 et Stripe réessaiera plus tard.)
        return $this->json(['received' => true, 'applied' => $applied]);
    }
}
