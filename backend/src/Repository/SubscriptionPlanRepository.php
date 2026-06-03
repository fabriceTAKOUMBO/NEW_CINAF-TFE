<?php
namespace App\Repository;

use App\Entity\SubscriptionPlan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SubscriptionPlan>
 */
class SubscriptionPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SubscriptionPlan::class);
    }

    public function findOneByStripePriceId(string $stripePriceId): ?SubscriptionPlan
    {
        return $this->findOneBy(['stripePriceId' => $stripePriceId]);
    }

    /** @return list<SubscriptionPlan> */
    public function findActive(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.isActive = true')
            ->orderBy('p.priceCents', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
