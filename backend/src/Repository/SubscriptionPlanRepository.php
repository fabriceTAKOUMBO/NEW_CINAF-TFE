<?php
namespace App\Repository;

use App\Entity\SubscriptionPlan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Accès aux formules d'abonnement payant (SubscriptionPlan).
 *
 * findActive() alimente la liste publique GET /api/subscription-plans ; les
 * autres lectures (souscription, admin, app:stripe:sync-plans, fixtures)
 * passent par find() / findOneBy() hérités.
 *
 * @extends ServiceEntityRepository<SubscriptionPlan>
 */
class SubscriptionPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SubscriptionPlan::class);
    }

    /** Formule liée au prix Stripe `$stripePriceId` (`price_...`), ou null (aucun appelant dans le code actuel). */
    public function findOneByStripePriceId(string $stripePriceId): ?SubscriptionPlan
    {
        return $this->findOneBy(['stripePriceId' => $stripePriceId]);
    }

    /**
     * Formules en vente (`isActive` = true), triées par prix croissant
     * (GET /api/subscription-plans).
     *
     * @return list<SubscriptionPlan>
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.isActive = true')
            ->orderBy('p.priceCents', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
