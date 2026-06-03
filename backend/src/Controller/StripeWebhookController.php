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
 */
class StripeWebhookController extends AbstractController
{
    public function __construct(
        private readonly StripeService $stripe,
        private readonly SubscriptionService $subService,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/stripe/webhook', methods: ['POST'])]
    public function handle(Request $request): JsonResponse
    {
        if (!$this->stripe->isEnabled()) {
            // Si STRIPE_ENABLED=false, on rejette les hits webhook (anti-bruit).
            return $this->json(['message' => 'Stripe est désactivé sur cette instance.'], 503);
        }

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
        return $this->json(['received' => true, 'applied' => $applied]);
    }
}
