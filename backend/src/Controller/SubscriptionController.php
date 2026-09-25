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
 * Préfixe `/api/subscriptions`, réservé aux utilisateurs connectés : ROLE_USER
 * via `#[IsGranted]` (aucune règle `access_control` ne couvre ce préfixe).
 *  - GET  /current              abonnement actif (ou null)
 *  - POST /subscribe            souscription à un plan
 *  - GET  /session/{sessionId}  statut d'une session Stripe Checkout (page de retour)
 *  - POST /cancel               résiliation différée à la fin de la période payée
 *  - POST /resume               annulation de cette résiliation avant l'échéance
 *  - GET  /payments             historique des factures Stripe
 *
 * `POST /subscribe` se comporte en 2 modes selon STRIPE_ENABLED :
 *  - false (mock) : active immédiatement côté serveur → 201 + sub.toArray().
 *  - true  (Stripe) : crée une Checkout Session en mode intégré (Embedded
 *    Checkout) → 200 + {mode: 'stripe', clientSecret, sessionId} ; le front
 *    affiche le formulaire de carte Stripe dans sa page grâce au `clientSecret`.
 *    L'abo sera réellement créé en DB côté webhook (`checkout.session.completed`).
 */
#[Route('/api/subscriptions')]
#[IsGranted('ROLE_USER')]
class SubscriptionController extends AbstractController
{
    /**
     * @param bool $stripeEnabled valeur de STRIPE_ENABLED, injectée par config/services.yaml
     */
    public function __construct(
        private readonly SubscriptionRepository $subRepo,
        private readonly SubscriptionPlanRepository $planRepo,
        private readonly SubscriptionService $subService,
        private readonly StripeService $stripe,
        private readonly bool $stripeEnabled,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Renvoie l'abonnement actif de l'utilisateur connecté (statut ACTIVE et
     * `endsAt` non dépassé), avec le détail de son plan.
     *
     * @return JsonResponse 200 `{subscription: Subscription::toArray()}` ou `{subscription: null}`
     */
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

    /**
     * Souscrit l'utilisateur connecté au plan demandé.
     *
     * Corps JSON : `{planId}` (UUID d'un plan actif).
     *  - Mode Stripe : rien n'est écrit en base ici. On ouvre une session
     *    Embedded Checkout et on renvoie son `clientSecret` ; l'abonnement local
     *    naîtra du webhook `checkout.session.completed` une fois le paiement validé.
     *    L'identifiant client Stripe de l'abonnement le plus récent est réutilisé
     *    pour regrouper l'historique de facturation chez Stripe.
     *  - Mode simulé : l'abonnement est activé tout de suite, sans paiement (un
     *    abonnement actif précédent passe en CANCELED, cf. SubscriptionService::subscribe()).
     *
     * @return JsonResponse 201 `Subscription::toArray()` (mode simulé) ;
     *                      200 `{mode: 'stripe', clientSecret, sessionId}` (mode Stripe) ;
     *                      400 planId absent ou mal formé, plan désactivé ou sans prix Stripe ;
     *                      404 plan introuvable
     */
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

        // Mode simulé (STRIPE_ENABLED=false : démo et suite de tests) : activation immédiate.
        $sub = $this->subService->subscribe($user, $plan);
        return $this->json($sub->toArray(), 201);
    }

    /**
     * Endpoint utilisé par la page de retour Embedded Checkout pour afficher
     * un statut immédiat (paid / unpaid) sans attendre le webhook.
     *
     * Simple lecture chez Stripe : n'écrit rien en base (seul le webhook crée
     * l'abonnement). La contrainte de route n'accepte que des identifiants de
     * session Checkout (`cs_…`). Aucune vérification ne rattache la session à
     * l'utilisateur connecté.
     *
     * @return JsonResponse 200 `{status, paymentStatus, customerEmail}` ; 404 session
     *                      introuvable (ou toute erreur Stripe) ; 503 si Stripe est désactivé
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
     * En mode simulé (ou pour un abonnement sans identifiant Stripe), aucun
     * webhook ne viendra : l'abonnement cesse simplement d'être actif une fois
     * `endsAt` dépassé, car la recherche de l'abonnement actif filtre sur cette date.
     *
     * @return JsonResponse 200 `{message, subscription}` ; 404 si aucun abonnement actif
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

        // Stripe n'est appelé que pour un abonnement réellement payé via Stripe ;
        // un abonnement simulé ou attribué par un admin n'a pas de stripeSubscriptionId.
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
     *
     * « Résilié-actif » = statut ACTIVE, `endsAt` futur et `canceledAt` renseigné.
     * En mode Stripe, `cancel_at_period_end` repasse à false chez Stripe pour
     * que les prélèvements reprennent à l'échéance.
     *
     * @return JsonResponse 200 `{message, subscription}` ; 404 si aucun abonnement actif ;
     *                      409 si l'abonnement n'est pas en cours de résiliation
     *                      (ou si SubscriptionService::resume() le refuse)
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
     *
     * Les factures sont lues en direct chez Stripe (aucune copie locale). Le
     * client Stripe est celui de l'abonnement le plus récent de l'utilisateur.
     *
     * @return JsonResponse 200 liste de factures (cf. StripeService::listInvoices()),
     *                      éventuellement vide — y compris en cas d'erreur Stripe
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
