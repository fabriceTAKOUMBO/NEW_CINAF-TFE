<?php
namespace App\Repository;

use App\Entity\Studio;
use App\Entity\StudioSubscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository de la liaison user ↔ studio (abonnement gratuit type
 * « follow YouTube »).
 *
 * @extends ServiceEntityRepository<StudioSubscription>
 */
class StudioSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StudioSubscription::class);
    }

    /**
     * Vérifie si un utilisateur est actuellement abonné à un studio.
     *
     * Implémentation : requête d'existence (`SELECT 1 ... LIMIT 1`) plutôt
     * que de matérialiser l'entité — c'est le pattern le moins coûteux pour
     * un endpoint « status » potentiellement appelé sur chaque page studio.
     */
    public function isSubscribed(User $user, Studio $studio): bool
    {
        $row = $this->createQueryBuilder('s')
            ->select('1')
            ->andWhere('s.user = :user')
            ->andWhere('s.studio = :studio')
            ->setParameter('user', $user)
            ->setParameter('studio', $studio)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row !== null;
    }

    /**
     * Compte le nombre d'abonnés actifs d'un studio donné. Utilisé pour
     * exposer `subscribersCount` sur les endpoints publics (carte studio
     * + fiche détail) et sur le dashboard producteur.
     */
    public function countByStudio(Studio $studio): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.studio = :studio')
            ->setParameter('studio', $studio)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Récupère l'abonnement existant pour un couple (user, studio), ou
     * `null` s'il n'existe pas. Utilisé côté contrôleur DELETE pour
     * retirer la ligne en base.
     */
    public function findOneByUserAndStudio(User $user, Studio $studio): ?StudioSubscription
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->andWhere('s.studio = :studio')
            ->setParameter('user', $user)
            ->setParameter('studio', $studio)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
