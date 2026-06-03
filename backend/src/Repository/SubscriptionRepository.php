<?php
namespace App\Repository;

use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Subscription>
 */
class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    /**
     * Retourne l'abonnement ACTIVE non expiré le plus récent pour un user, ou null.
     * Un user n'est censé avoir qu'un seul abo ACTIVE à la fois (subscribe annule
     * le précédent), mais en cas d'incohérence, on prend le plus récent.
     */
    public function findOneByStripeSubscriptionId(string $stripeSubId): ?Subscription
    {
        return $this->findOneBy(['stripeSubscriptionId' => $stripeSubId]);
    }

    public function findCurrentActiveForUser(User $user): ?Subscription
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->andWhere('s.status = :active')
            ->andWhere('s.endsAt IS NULL OR s.endsAt > :now')
            ->setParameter('user', $user)
            ->setParameter('active', Subscription::STATUS_ACTIVE)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('s.startsAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Version batch : retourne pour chaque userId fourni l'abonnement ACTIVE
     * non expiré le plus récent (ou rien). Évite le N+1 dans la liste admin.
     *
     * @param list<\Symfony\Component\Uid\Uuid|string> $userIds
     * @return array<string, Subscription> map indexée par userId UUID v4 string
     */
    public function findCurrentActiveByUserIds(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $rows = $this->createQueryBuilder('s')
            ->select('s', 'p', 'u')
            ->join('s.plan', 'p')
            ->join('s.user', 'u')
            ->andWhere('u.id IN (:ids)')
            ->andWhere('s.status = :active')
            ->andWhere('s.endsAt IS NULL OR s.endsAt > :now')
            ->setParameter('ids', $userIds)
            ->setParameter('active', Subscription::STATUS_ACTIVE)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('s.startsAt', 'DESC')
            ->getQuery()
            ->getResult();

        // Pour chaque user, on garde la première (= la plus récente vu l'ORDER BY).
        $byUser = [];
        foreach ($rows as $sub) {
            $uid = $sub->getUser()->getId()->toRfc4122();
            if (!isset($byUser[$uid])) {
                $byUser[$uid] = $sub;
            }
        }
        return $byUser;
    }
}
