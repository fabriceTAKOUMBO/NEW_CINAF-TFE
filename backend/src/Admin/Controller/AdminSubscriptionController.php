<?php
namespace App\Admin\Controller;

use App\Repository\SubscriptionPlanRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
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
 * Gestion des abonnements depuis l'interface admin
 * (`/admin/utilisateurs/{id}` côté frontend).
 */
#[IsGranted('ROLE_ADMIN')]
class AdminSubscriptionController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly SubscriptionRepository $subRepo,
        private readonly SubscriptionPlanRepository $planRepo,
        private readonly SubscriptionService $subService,
        private readonly StripeService $stripe,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/admin/users/{id}/subscription', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $user = $this->resolveUser($id);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $sub = $this->subRepo->findCurrentActiveForUser($user);
        return $this->json([
            'subscription' => $sub?->toArray(),
        ]);
    }

    #[Route('/api/admin/users/{id}/subscription', methods: ['POST'])]
    public function assign(string $id, Request $request): JsonResponse
    {
        $user = $this->resolveUser($id);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || empty($data['planId'])) {
            return $this->json(['message' => 'Le champ "planId" est requis.'], 400);
        }

        try {
            $planUuid = Uuid::fromString((string) $data['planId']);
        } catch (\InvalidArgumentException) {
            return $this->json(['message' => 'planId invalide.'], 400);
        }

        $plan = $this->planRepo->findOneBy(['id' => $planUuid]);
        if (!$plan) {
            return $this->json(['message' => 'Plan introuvable.'], 404);
        }

        $sub = $this->subService->subscribe($user, $plan);
        return $this->json($sub->toArray(), 201);
    }

    /**
     * Modifie l'abonnement actif d'un utilisateur :
     * - `planId` (optionnel) : change le plan
     * - `startsAt` (optionnel, ISO 8601 ou date YYYY-MM-DD) : change la date de début
     * `endsAt` est recalculé automatiquement à partir du couple (startsAt, plan).
     */
    #[Route('/api/admin/users/{id}/subscription', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $user = $this->resolveUser($id);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $sub = $this->subRepo->findCurrentActiveForUser($user);
        if (!$sub) {
            return $this->json(['message' => "Aucun abonnement actif à modifier. Activez-en un d'abord."], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['message' => 'Corps JSON invalide.'], 400);
        }

        $plan = null;
        if (\array_key_exists('planId', $data) && $data['planId'] !== null && $data['planId'] !== '') {
            try {
                $planUuid = Uuid::fromString((string) $data['planId']);
            } catch (\InvalidArgumentException) {
                return $this->json(['message' => 'planId invalide.'], 400);
            }
            $plan = $this->planRepo->findOneBy(['id' => $planUuid]);
            if (!$plan) {
                return $this->json(['message' => 'Plan introuvable.'], 404);
            }
        }

        $startsAt = null;
        if (\array_key_exists('startsAt', $data) && $data['startsAt'] !== null && $data['startsAt'] !== '') {
            try {
                $startsAt = new \DateTimeImmutable((string) $data['startsAt']);
            } catch (\Exception) {
                return $this->json(['message' => 'Date de début invalide (format ISO attendu).'], 400);
            }
        }

        if ($plan === null && $startsAt === null) {
            return $this->json(['message' => 'Au moins un champ (planId ou startsAt) doit être fourni.'], 400);
        }

        $updated = $this->subService->update($sub, $plan, $startsAt);
        return $this->json($updated->toArray());
    }

    /**
     * Résiliation différée (admin) : équivalent du flux user mais piloté
     * depuis le back-office. Stripe `cancel_at_period_end=true`, local reste ACTIVE.
     */
    #[Route('/api/admin/users/{id}/subscription', methods: ['DELETE'])]
    public function cancel(string $id): JsonResponse
    {
        $user = $this->resolveUser($id);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $sub = $this->subRepo->findCurrentActiveForUser($user);
        if (!$sub) {
            return $this->json(['message' => 'Aucun abonnement actif à annuler.'], 404);
        }

        $stripeId = $sub->getStripeSubscriptionId();
        if ($this->stripe->isEnabled() && $stripeId !== null) {
            try {
                $this->stripe->cancelAtPeriodEnd($stripeId);
            } catch (\Throwable $e) {
                // Idempotent local : on poursuit même si Stripe est down, mais on
                // trace l'échec (sinon une désync local/Stripe passe inaperçue).
                $this->logger->warning('Stripe cancelAtPeriodEnd (admin) a échoué ; annulation locale poursuivie.', [
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
     * Réactive (admin) un abonnement résilié de manière différée
     * tant que la période payée n'est pas terminée. 409 sinon.
     */
    #[Route('/api/admin/users/{id}/subscription/resume', methods: ['POST'])]
    public function resume(string $id): JsonResponse
    {
        $user = $this->resolveUser($id);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $sub = $this->subRepo->findCurrentActiveForUser($user);
        if (!$sub) {
            return $this->json(['message' => 'Aucun abonnement actif à réactiver.'], 404);
        }
        if ($sub->getCanceledAt() === null) {
            return $this->json(['message' => "Cet abonnement n'est pas en cours de résiliation."], 409);
        }

        $stripeId = $sub->getStripeSubscriptionId();
        if ($this->stripe->isEnabled() && $stripeId !== null) {
            try {
                $this->stripe->resumeAtPeriodEnd($stripeId);
            } catch (\Throwable $e) {
                // Idem cancel : poursuite locale + trace de l'échec Stripe.
                $this->logger->warning('Stripe resumeAtPeriodEnd (admin) a échoué ; réactivation locale poursuivie.', [
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
     * Historique des paiements (invoices Stripe) d'un user vu côté admin.
     * Renvoie `[]` si l'utilisateur n'a jamais payé via Stripe ou si Stripe est désactivé.
     * 404 si l'utilisateur est inconnu.
     */
    #[Route('/api/admin/users/{id}/payments', methods: ['GET'])]
    public function payments(string $id): JsonResponse
    {
        $user = $this->resolveUser($id);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        if (!$this->stripe->isEnabled()) {
            return $this->json([]);
        }

        $customerId = $this->subService->findExistingStripeCustomerIdForUser($user);
        if ($customerId === null) {
            return $this->json([]);
        }

        try {
            $invoices = $this->stripe->listInvoices($customerId);
        } catch (\Throwable) {
            return $this->json([]);
        }

        return $this->json($invoices);
    }

    private function resolveUser(string $id): \App\Entity\User|JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            return $this->json(['message' => 'Identifiant utilisateur invalide.'], 400);
        }
        $user = $this->userRepo->findOneBy(['id' => $uuid]);
        if (!$user) {
            return $this->json(['message' => 'Utilisateur introuvable.'], 404);
        }
        return $user;
    }
}
