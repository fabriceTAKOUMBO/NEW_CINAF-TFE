<?php
namespace App\Controller;

use App\Entity\SubscriptionPlan;
use App\Repository\SubscriptionPlanRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Endpoints publics : liste et détail des plans d'abonnement disponibles.
 */
#[Route('/api/subscription-plans')]
class SubscriptionPlanController extends AbstractController
{
    public function __construct(
        private readonly SubscriptionPlanRepository $planRepo,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $plans = $this->planRepo->findActive();
        return $this->json(array_map(fn(SubscriptionPlan $p) => $p->toArray(), $plans));
    }

    #[Route('/{id}', methods: ['GET'])]
    public function detail(string $id): JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            return $this->json(['message' => 'Identifiant invalide.'], 400);
        }

        $plan = $this->planRepo->findOneBy(['id' => $uuid]);
        if (!$plan) {
            return $this->json(['message' => 'Plan introuvable.'], 404);
        }

        return $this->json($plan->toArray());
    }
}
