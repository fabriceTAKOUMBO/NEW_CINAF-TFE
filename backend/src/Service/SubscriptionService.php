<?php
namespace App\Service;

use App\Entity\Subscription;
use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\Repository\SubscriptionPlanRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Encapsule la logique métier des abonnements. Deux chemins :
 *  - `subscribe()` : activation immédiate côté serveur (mode mock / démo).
 *  - `syncFromStripeEvent()` : réconciliation depuis un webhook Stripe.
 *
 * S'y ajoutent les opérations communes aux deux modes : résiliation différée
 * (`cancel()`), réactivation (`resume()`), modification par un admin
 * (`update()`) et le résumé exposé par `GET /api/auth/me` (`summarize()`).
 * Ce service n'appelle jamais l'API Stripe : ce rôle revient à StripeService,
 * invoqué directement par les contrôleurs.
 *
 * Statuts d'un abonnement (`Subscription`) :
 *  - ACTIVE   : donne accès à la lecture tant que `endsAt` n'est pas dépassé
 *               (une résiliation différée garde ACTIVE et renseigne `canceledAt`) ;
 *  - CANCELED : remplacé par un nouvel abonnement (changement de plan), annulé
 *               immédiatement (ancienne logique) ou statut Stripe canceled ;
 *  - EXPIRED  : terminé côté Stripe (`customer.subscription.deleted`) ou
 *               statut Stripe sans accès (past_due, unpaid…).
 */
class SubscriptionService
{
    public function __construct(
        private readonly SubscriptionRepository $subRepo,
        private readonly SubscriptionPlanRepository $planRepo,
        private readonly UserRepository $userRepo,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Indique si l'utilisateur a un abonnement qui donne accès à la lecture
     * (statut ACTIVE et `endsAt` non dépassé). Utilisé par l'endpoint
     * `GET /api/catalogue/discover/{slug}/can-play`.
     */
    public function hasActiveSubscription(User $user): bool
    {
        $sub = $this->subRepo->findCurrentActiveForUser($user);
        return $sub !== null && $sub->isCurrentlyActive();
    }

    /**
     * Crée un abonnement ACTIVE pour l'utilisateur. Si un abo actif existe déjà,
     * il est marqué CANCELED (l'utilisateur peut "changer de plan"). L'endsAt
     * est calculé à partir de l'unité d'intervalle du plan.
     *
     * Aucun paiement ni appel à Stripe : utilisé en mode simulé
     * (SubscriptionController::subscribe()) et quand un admin attribue un plan
     * (AdminSubscriptionController::assign()). L'ancien abonnement n'est annulé
     * qu'en base.
     */
    public function subscribe(User $user, SubscriptionPlan $plan): Subscription
    {
        $existing = $this->subRepo->findCurrentActiveForUser($user);
        if ($existing !== null) {
            $existing->setStatus(Subscription::STATUS_CANCELED);
            $existing->setCanceledAt(new \DateTimeImmutable());
        }

        $now = new \DateTimeImmutable();
        $endsAt = $this->computeEndsAt($now, $plan);

        $sub = new Subscription();
        $sub->setUser($user);
        $sub->setPlan($plan);
        $sub->setStatus(Subscription::STATUS_ACTIVE);
        $sub->setStartsAt($now);
        $sub->setEndsAt($endsAt);

        $this->em->persist($sub);
        $this->em->flush();

        return $sub;
    }

    /**
     * Modifie un abonnement existant (admin) : plan et/ou date de début.
     * `endsAt` est systématiquement recalculé à partir du couple (startsAt, plan).
     * Le statut est restauré à ACTIVE (utile si l'admin "réactive" un abo en
     * changeant la date de début).
     *
     * En pratique, AdminSubscriptionController ne lui transmet que l'abonnement
     * actif courant : l'effet visible est alors l'effacement de `canceledAt`
     * (annulation d'une résiliation différée, en base uniquement, sans appel à Stripe).
     */
    public function update(Subscription $sub, ?SubscriptionPlan $plan, ?\DateTimeImmutable $startsAt): Subscription
    {
        if ($plan !== null) {
            $sub->setPlan($plan);
        }
        if ($startsAt !== null) {
            $sub->setStartsAt($startsAt);
        }
        $sub->setEndsAt($this->computeEndsAt($sub->getStartsAt(), $sub->getPlan()));
        // Édition par l'admin → on remet l'abo en ACTIVE et on efface canceledAt.
        $sub->setStatus(Subscription::STATUS_ACTIVE);
        $sub->setCanceledAt(null);

        $this->em->flush();
        return $sub;
    }

    /**
     * Marque un abonnement comme « résilié ».
     *
     * Deux sémantiques selon `$immediate` :
     *  - `false` (résiliation différée, défaut métier 2026-05-20) :
     *    `status` reste `ACTIVE`, `canceledAt = now`, `endsAt` PRÉSERVÉ. L'utilisateur
     *    conserve l'accès jusqu'à `endsAt`. Le webhook `customer.subscription.deleted`
     *    basculera ensuite le status en `EXPIRED` à la fin de la période.
     *  - `true` (legacy — annulation immédiate) : `status = CANCELED`, `endsAt = now`.
     *    Conservé pour compatibilité descendante des données historiques mais
     *    plus utilisé dans le code nouveau (`STATUS_CANCELED` est legacy).
     */
    public function cancel(Subscription $sub, bool $immediate = false): void
    {
        $sub->setCanceledAt(new \DateTimeImmutable());
        if ($immediate) {
            $sub->setStatus(Subscription::STATUS_CANCELED);
            $sub->setEndsAt(new \DateTimeImmutable());
        }
        // En mode différé, on ne touche ni à status (reste ACTIVE) ni à endsAt
        // (date de fin de période payée renvoyée par Stripe).
        $this->em->flush();
    }

    /**
     * Réactive un abonnement précédemment résilié de manière différée :
     * efface `canceledAt`. Le `status` reste `ACTIVE` et l'`endsAt` n'est pas touché.
     * Lève si l'abonnement est déjà expiré (status != ACTIVE ou endsAt dépassé).
     *
     * @throws \DomainException si l'abonnement n'est pas ACTIVE ou si sa période payée est terminée
     */
    public function resume(Subscription $sub): void
    {
        if ($sub->getStatus() !== Subscription::STATUS_ACTIVE) {
            throw new \DomainException('Seul un abonnement ACTIVE peut être réactivé.');
        }
        if ($sub->getEndsAt() !== null && $sub->getEndsAt() <= new \DateTimeImmutable()) {
            throw new \DomainException('La période payée est terminée — réactivation impossible.');
        }
        $sub->setCanceledAt(null);
        $this->em->flush();
    }

    /**
     * Résumé compact d'un abonnement, exposé dans la réponse de `GET /api/auth/me`
     * (champ `subscription`) ; null si aucun abonnement n'est fourni.
     *
     * @return array{
     *   id:string, status:string, planName:string,
     *   startsAt:string, endsAt:?string, canceledAt:?string,
     *   isCurrentlyActive:bool
     * }|null
     */
    public function summarize(?Subscription $sub): ?array
    {
        if ($sub === null) {
            return null;
        }
        return [
            'id' => $sub->getId()->toRfc4122(),
            'status' => $sub->getStatus(),
            'planName' => $sub->getPlan()->getName(),
            'startsAt' => $sub->getStartsAt()->format(\DateTimeInterface::ATOM),
            'endsAt' => $sub->getEndsAt()?->format(\DateTimeInterface::ATOM),
            'canceledAt' => $sub->getCanceledAt()?->format(\DateTimeInterface::ATOM),
            'isCurrentlyActive' => $sub->isCurrentlyActive(),
        ];
    }

    /**
     * Calcule la fin de période : début + `intervalCount` × `intervalUnit` du plan.
     *
     * `year` → années, toute autre unité → mois ; un `intervalCount` inférieur
     * à 1 est ramené à 1. Arithmétique de `modify()` : un mois ajouté à un
     * 31 janvier 2026 donne le 3 mars 2026 (février n'ayant pas de 31).
     */
    private function computeEndsAt(\DateTimeImmutable $start, SubscriptionPlan $plan): \DateTimeImmutable
    {
        $count = max(1, $plan->getIntervalCount());
        $unit = $plan->getIntervalUnit() === SubscriptionPlan::INTERVAL_YEAR ? 'years' : 'months';
        return $start->modify("+{$count} {$unit}");
    }

    /**
     * Récupère le stripeCustomerId d'un user, lu sur son abonnement le plus
     * récent (tri `createdAt` DESC), pour réutilisation côté Checkout et pour
     * lire ses factures. Null si jamais payé — ou si l'abonnement le plus récent
     * n'est pas passé par Stripe (simulé ou attribué par un admin), même si un
     * abonnement plus ancien l'était.
     */
    public function findExistingStripeCustomerIdForUser(User $user): ?string
    {
        $last = $this->subRepo->findOneBy(
            ['user' => $user],
            ['createdAt' => 'DESC'],
        );
        return $last?->getStripeCustomerId();
    }

    /**
     * Réconcilie un event Stripe avec l'état DB. Idempotent : un même event
     * peut être rejoué sans effet de bord (Stripe garantit la livraison
     * at-least-once). On retourne true si la sync a fait quelque chose, false
     * si l'event était ignorable (type inconnu ou déjà appliqué).
     *
     * Appelé par StripeWebhookController une fois la signature vérifiée ; le
     * booléen est renvoyé à Stripe dans le champ `applied` et journalisé.
     * `$event->data->object` est déjà typé par stripe-php selon l'événement
     * (Checkout\Session ou Subscription).
     */
    public function syncFromStripeEvent(\Stripe\Event $event): bool
    {
        return match ($event->type) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($event->data->object),
            'customer.subscription.updated' => $this->handleSubscriptionUpdated($event->data->object),
            'customer.subscription.deleted' => $this->handleSubscriptionDeleted($event->data->object),
            default => false,
        };
    }

    /**
     * checkout.session.completed → crée la Subscription en DB en mode ACTIVE.
     * Idempotent : si stripeSubscriptionId est déjà en DB, on no-op.
     *
     * L'utilisateur et le plan sont retrouvés grâce aux métadonnées `user_id` /
     * `plan_id` posées par StripeService::createCheckoutSession(). `endsAt` est
     * d'abord calculé localement (même règle que le mode simulé) puis recalé par
     * `customer.subscription.updated` lorsque l'événement porte `current_period_end`.
     */
    private function handleCheckoutCompleted(\Stripe\Checkout\Session $session): bool
    {
        if ($session->mode !== 'subscription' || $session->subscription === null) {
            return false; // ignore les sessions one-shot ou non finalisées
        }

        // Stripe fournit soit l'ID (chaîne), soit l'objet complet si le champ a été « expand ».
        $stripeSubId = is_string($session->subscription) ? $session->subscription : $session->subscription->id;
        // Clé d'idempotence : l'identifiant de l'abonnement Stripe.
        if ($this->subRepo->findOneByStripeSubscriptionId($stripeSubId) !== null) {
            return false; // déjà appliqué
        }

        [$user, $plan] = $this->resolveUserAndPlanFromSession($session);
        if ($user === null || $plan === null) {
            return false; // metadata manquantes ou entités introuvables → on n'invente rien
        }

        // Annule l'abonnement actif précédent (changement de plan).
        // Uniquement en base : aucun appel n'est fait à Stripe pour l'ancien abonnement.
        $existing = $this->subRepo->findCurrentActiveForUser($user);
        if ($existing !== null) {
            $existing->setStatus(Subscription::STATUS_CANCELED);
            $existing->setCanceledAt(new \DateTimeImmutable());
        }

        $now = new \DateTimeImmutable();
        $sub = new Subscription();
        $sub->setUser($user);
        $sub->setPlan($plan);
        $sub->setStatus(Subscription::STATUS_ACTIVE);
        $sub->setStartsAt($now);
        $sub->setEndsAt($this->computeEndsAt($now, $plan));
        $sub->setStripeSubscriptionId($stripeSubId);
        $customerId = is_string($session->customer) ? $session->customer : $session->customer?->id;
        if ($customerId !== null) {
            $sub->setStripeCustomerId($customerId);
        }

        $this->em->persist($sub);
        $this->em->flush();
        return true;
    }

    /**
     * customer.subscription.updated → renouvellement, changement de statut, ou
     * basculement `cancel_at_period_end` (résiliation différée / réactivation).
     *
     * Comportement par flag :
     *  - `cancel_at_period_end = true` : résiliation différée → set `canceledAt = now`,
     *    `status` reste ACTIVE, `endsAt` reflète la fin de période (peut être rafraîchi
     *    depuis Stripe).
     *  - `cancel_at_period_end = false` : réactivation → `canceledAt = null`.
     *  - sinon : renouvellement classique, refresh de `endsAt` depuis `current_period_end`.
     *
     * Le rafraîchissement de `endsAt` a lieu AVANT le test du flag, donc dans
     * les trois cas. Points d'attention sur le code actuel :
     *  - l'API Stripe fournit toujours `cancel_at_period_end` (booléen) : la
     *    branche « sinon » (conversion du statut Stripe) ne s'exécute que si le
     *    champ manque, comme dans les payloads construits par les tests ;
     *  - depuis la version d'API Stripe 2025-03-31 (« basil »), `current_period_end`
     *    est exposé sur les items de l'abonnement et non plus sur l'abonnement :
     *    avec une telle version, `endsAt` n'est pas rafraîchi ici.
     */
    private function handleSubscriptionUpdated(\Stripe\Subscription $stripeSub): bool
    {
        $local = $this->subRepo->findOneByStripeSubscriptionId($stripeSub->id);
        if ($local === null) {
            return false; // pas de subscription locale → l'event précède checkout.completed, on ignore
        }

        // current_period_end = timestamp Unix de fin de période. Source d'autorité pour endsAt.
        $periodEnd = $stripeSub->current_period_end ?? null;
        if ($periodEnd !== null) {
            $local->setEndsAt((new \DateTimeImmutable())->setTimestamp($periodEnd));
        }

        // Gestion du flag cancel_at_period_end (résiliation différée / réactivation).
        // Le flag est exposé brut par stripe-php sur l'objet Subscription.
        $cancelAtPeriodEnd = $stripeSub->cancel_at_period_end ?? null;
        if ($cancelAtPeriodEnd === true) {
            // Résiliation différée : on conserve ACTIVE + endsAt, on date juste canceledAt.
            if ($local->getCanceledAt() === null) {
                $local->setCanceledAt(new \DateTimeImmutable());
            }
            $local->setStatus(Subscription::STATUS_ACTIVE);
            $this->em->flush();
            return true;
        }
        if ($cancelAtPeriodEnd === false) {
            // Réactivation explicite après une résiliation différée.
            $local->setCanceledAt(null);
            $local->setStatus(Subscription::STATUS_ACTIVE);
            $this->em->flush();
            return true;
        }

        // Sinon : update générique (renouvellement, past_due, etc.).
        $local->setStatus($this->mapStripeStatusToLocal($stripeSub->status));

        $this->em->flush();
        return true;
    }

    /**
     * customer.subscription.deleted → fin de période atteinte côté Stripe.
     * Le local passe en EXPIRED (sémantique « plus d'accès »). `STATUS_CANCELED`
     * est réservé à l'ancienne logique « annulation immédiate » et n'est plus
     * utilisé ici.
     */
    private function handleSubscriptionDeleted(\Stripe\Subscription $stripeSub): bool
    {
        $local = $this->subRepo->findOneByStripeSubscriptionId($stripeSub->id);
        if ($local === null) {
            return false;
        }
        $local->setStatus(Subscription::STATUS_EXPIRED);
        // canceledAt conserve la date de la demande de résiliation si elle existe.
        if ($local->getCanceledAt() === null) {
            $local->setCanceledAt(new \DateTimeImmutable());
        }
        // Fin d'accès immédiate : Stripe n'émet cet événement qu'à l'échéance d'une
        // résiliation différée ou lors d'une annulation immédiate faite chez Stripe.
        $local->setEndsAt(new \DateTimeImmutable());
        $this->em->flush();
        return true;
    }

    /**
     * Retrouve l'utilisateur et le plan désignés par les métadonnées de la
     * session Checkout (`user_id`, `plan_id`). Un identifiant absent, mal formé
     * ou inconnu donne null, sans exception.
     *
     * @return array{0: ?User, 1: ?SubscriptionPlan}
     */
    private function resolveUserAndPlanFromSession(\Stripe\Checkout\Session $session): array
    {
        $metadata = $session->metadata?->toArray() ?? [];
        $userId = $metadata['user_id'] ?? null;
        $planId = $metadata['plan_id'] ?? null;

        $user = null;
        if ($userId !== null) {
            try {
                $user = $this->userRepo->find(Uuid::fromString((string) $userId));
            } catch (\InvalidArgumentException) {
                $user = null;
            }
        }

        $plan = null;
        if ($planId !== null) {
            try {
                $plan = $this->planRepo->find(Uuid::fromString((string) $planId));
            } catch (\InvalidArgumentException) {
                $plan = null;
            }
        }

        return [$user, $plan];
    }

    /**
     * Mapping des statuts Stripe vers les 3 statuts locaux (ACTIVE/CANCELED/EXPIRED).
     * trialing/active → ACTIVE, canceled/incomplete_expired → CANCELED, past_due/unpaid → EXPIRED.
     * (Tout autre statut — incomplete, paused… — tombe aussi dans EXPIRED.)
     */
    private function mapStripeStatusToLocal(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'active', 'trialing' => Subscription::STATUS_ACTIVE,
            'canceled', 'incomplete_expired' => Subscription::STATUS_CANCELED,
            default => Subscription::STATUS_EXPIRED,
        };
    }
}
