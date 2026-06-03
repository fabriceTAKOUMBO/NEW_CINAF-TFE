<?php
namespace App\Controller;

use App\Entity\User;
use App\Repository\SubscriptionPlanRepository;
use App\Repository\SubscriptionRepository;
use App\Service\StripeService;
use App\Service\SubscriptionService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Endpoints user-side pour gérer son propre abonnement.
 *
 * `POST /subscribe` se comporte en 2 modes selon STRIPE_ENABLED :
 *  - false (mock) : active immédiatement côté serveur → 201 + sub.toArray().
 *  - true  (Stripe) : crée une Checkout Session → 200 + {checkoutUrl}.
 *    L'abo sera réellement créé en DB côté webhook (`checkout.session.completed`).
 */
#[Route('/api/subscriptions')]
#[IsGranted('ROLE_USER')]
class SubscriptionController extends AbstractController
{
    public function __construct(
        private readonly SubscriptionRepository $subRepo,
        private readonly SubscriptionPlanRepository $planRepo,
        private readonly SubscriptionService $subService,
        private readonly StripeService $stripe,
        private readonly bool $stripeEnabled,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/current', methods: ['GET'])]
    public function current(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $sub = $this->subRepo->findCurrentActiveForUser($user);
        return $this->json([
            'subscription' => $sub?->toArray(),
        ]);
    }

    #[Route('/subscribe', methods: ['POST'])]
    public function subscribe(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || empty($data['planId'])) {
            return $this->json(['message' => 'Le champ "planId" est requis.'], 400);
        }

        try {
            $uuid = Uuid::fromString((string) $data['planId']);
        } catch (\InvalidArgumentException) {
            return $this->json(['message' => 'planId invalide.'], 400);
        }

        $plan = $this->planRepo->findOneBy(['id' => $uuid]);
        if (!$plan) {
            return $this->json(['message' => 'Plan introuvable.'], 404);
        }
        if (!$plan->isActive()) {
            return $this->json(['message' => 'Ce plan n\'est plus disponible.'], 400);
        }

        if ($this->stripeEnabled) {
            if ($plan->getStripePriceId() === null) {
                return $this->json(['message' => 'Ce plan n\'est pas encore configuré côté Stripe.'], 400);
            }
            $existingCustomerId = $this->subService->findExistingStripeCustomerIdForUser($user);
            $session = $this->stripe->createCheckoutSession($user, $plan, $existingCustomerId);
            return $this->json([
                'mode' => 'stripe',
                'clientSecret' => $session['clientSecret'],
                'sessionId' => $session['id'],
            ]);
        }

        $sub = $this->subService->subscribe($user, $plan);
        return $this->json($sub->toArray(), 201);
    }

    /**
     * Endpoint utilisé par la page de retour Embedded Checkout pour afficher
     * un statut immédiat (paid / unpaid) sans attendre le webhook.
     */
    #[Route('/session/{sessionId}', methods: ['GET'], requirements: ['sessionId' => 'cs_[a-zA-Z0-9_]+'])]
    public function sessionStatus(string $sessionId): JsonResponse
    {
        if (!$this->stripeEnabled) {
            return $this->json(['message' => 'Stripe est désactivé sur cette instance.'], 503);
        }
        try {
            $session = $this->stripe->retrieveCheckoutSession($sessionId);
        } catch (\Throwable) {
            return $this->json(['message' => 'Session introuvable.'], 404);
        }
        return $this->json([
            'status' => $session->status,                // 'open' | 'complete' | 'expired'
            'paymentStatus' => $session->payment_status, // 'paid' | 'unpaid' | 'no_payment_required'
            'customerEmail' => $session->customer_details?->email,
        ]);
    }

    /**
     * Résiliation différée à la fin de la période payée :
     *  - Stripe : `cancel_at_period_end = true` (plus de prélèvement, accès maintenu jusqu'à endsAt).
     *  - Local : `status` reste ACTIVE, `canceledAt = now`, `endsAt` préservé.
     * Le webhook `customer.subscription.deleted` basculera ensuite en EXPIRED.
     */
    #[Route('/cancel', methods: ['POST'])]
    public function cancel(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $sub = $this->subRepo->findCurrentActiveForUser($user);
        if (!$sub) {
            return $this->json(['message' => 'Aucun abonnement actif à annuler.'], 404);
        }

        $stripeId = $sub->getStripeSubscriptionId();
        if ($this->stripeEnabled && $stripeId !== null) {
            try {
                $this->stripe->cancelAtPeriodEnd($stripeId);
            } catch (\Throwable $e) {
                // On poursuit l'annulation locale même si Stripe est injoignable.
                // Le webhook re-synchronisera l'état si Stripe finit par répondre.
                // On trace toutefois l'échec pour diagnostiquer une éventuelle
                // désynchronisation entre l'état local et Stripe.
                $this->logger->warning('Stripe cancelAtPeriodEnd a échoué ; annulation locale poursuivie.', [
                    'stripeSubscriptionId' => $stripeId,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $this->subService->cancel($sub, immediate: false);
        return $this->json([
            'message' => 'Résiliation programmée à la fin de la période payée.',
            'subscription' => $sub->toArray(),
        ]);
    }

    /**
     * Réactive un abonnement précédemment résilié de manière différée
     * (tant que la période payée n'est pas terminée).
     * Renvoie 409 si l'abo n'est pas dans l'état « résilié-actif ».
     */
    #[Route('/resume', methods: ['POST'])]
    public function resume(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $sub = $this->subRepo->findCurrentActiveForUser($user);
        if (!$sub) {
            return $this->json(['message' => 'Aucun abonnement actif à réactiver.'], 404);
        }
        if ($sub->getCanceledAt() === null) {
            return $this->json(['message' => "Cet abonnement n'est pas en cours de résiliation."], 409);
        }

        $stripeId = $sub->getStripeSubscriptionId();
        if ($this->stripeEnabled && $stripeId !== null) {
            try {
                $this->stripe->resumeAtPeriodEnd($stripeId);
            } catch (\Throwable $e) {
                // Idem cancel : on poursuit côté local, le webhook resynchronisera.
                $this->logger->warning('Stripe resumeAtPeriodEnd a échoué ; réactivation locale poursuivie.', [
                    'stripeSubscriptionId' => $stripeId,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        try {
            $this->subService->resume($sub);
        } catch (\DomainException $e) {
            return $this->json(['message' => $e->getMessage()], 409);
        }

        return $this->json([
            'message' => 'Abonnement réactivé.',
            'subscription' => $sub->toArray(),
        ]);
    }

    /**
     * Historique des paiements (invoices Stripe) de l'utilisateur courant.
     * Renvoie un tableau vide si l'utilisateur n'a jamais payé via Stripe
     * (pas de stripeCustomerId) ou si Stripe est désactivé sur cette instance.
     */
    #[Route('/payments', methods: ['GET'])]
    public function payments(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->stripeEnabled) {
            return $this->json([]);
        }

        $customerId = $this->subService->findExistingStripeCustomerIdForUser($user);
        if ($customerId === null) {
            return $this->json([]);
        }

        try {
            $invoices = $this->stripe->listInvoices($customerId);
        } catch (\Throwable) {
            // En cas d'erreur Stripe transverse, on dégrade gracieusement plutôt que 500.
            return $this->json([]);
        }

        return $this->json($invoices);
    }
}
