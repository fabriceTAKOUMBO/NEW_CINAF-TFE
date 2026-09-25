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
 *
 * Préfixe `/api/subscription-plans`, sans authentification (aucun
 * `#[IsGranted]` ni règle `access_control`) : la page `/abonnement` du front
 * affiche les offres (mensuelle, annuelle…) avant même la connexion.
 *  - GET /       plans actifs, du moins cher au plus cher
 *  - GET /{id}   détail d'un plan
 *
 * Un plan (`SubscriptionPlan`) décrit une offre : prix en centimes, devise,
 * durée (`intervalUnit` month/year × `intervalCount`) et, en mode Stripe,
 * l'identifiant du prix Stripe correspondant (`stripePriceId`).
 */
#[Route('/api/subscription-plans')]
class SubscriptionPlanController extends AbstractController
{
    public function __construct(
        private readonly SubscriptionPlanRepository $planRepo,
    ) {
    }

    /**
     * Liste les plans proposés à la souscription (`isActive = true`), triés par
     * prix croissant. Les plans désactivés n'apparaissent pas.
     *
     * @return JsonResponse 200 tableau JSON de `SubscriptionPlan::toArray()`
     */
    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $plans = $this->planRepo->findActive();
        return $this->json(array_map(fn(SubscriptionPlan $p) => $p->toArray(), $plans));
    }

    /**
     * Renvoie le détail d'un plan par son UUID.
     *
     * Contrairement à la liste, un plan désactivé est aussi renvoyé (son champ
     * `isActive` vaut alors false) ; c'est `POST /api/subscriptions/subscribe`
     * qui refuse d'y souscrire.
     *
     * @return JsonResponse 200 `SubscriptionPlan::toArray()` ; 400 UUID mal formé ; 404 plan introuvable
     */
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
