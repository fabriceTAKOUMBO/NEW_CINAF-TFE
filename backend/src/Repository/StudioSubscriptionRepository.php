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
 * À ne pas confondre avec SubscriptionRepository (abonnement payant).
 * Appelé par StudioPublicController (suivre / ne plus suivre, état du suivi,
 * compteurs `subscribersCount`) et par le tableau de bord GET /api/studio/me.
 * Chaque ligne est un suivi en cours : se désabonner supprime la ligne.
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
     *
     * Appelée par GET /api/studios/{slug}/subscription et, pour rendre
     * l'abonnement idempotent, par POST /api/studios/{slug}/subscribe.
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
     *
     * Précision : les cartes des listes publiques passent désormais par la
     * variante groupée countByStudios() ; countByStudio() sert à la fiche
     * détail, aux réponses de POST /api/studios/{slug}/subscribe et au
     * tableau de bord GET /api/studio/me.
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
     * Nombre d'abonnés par studio, en UNE requête pour toute une page de
     * résultats (les listes publiques faisaient un COUNT par studio).
     *
     * @param  list<Studio>       $studios
     * @return array<string, int> id du studio (RFC 4122) => nombre
     */
    public function countByStudios(array $studios): array
    {
        if ($studios === []) {
            return [];
        }
        // IDENTITY() lit directement la FK studio_id. Un studio sans abonné est
        // absent du résultat : l'appelant prévoit la valeur par défaut 0.
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.studio) AS studioId, COUNT(s.id) AS nb')
            ->andWhere('s.studio IN (:studios)')
            ->setParameter('studios', $studios)
            ->groupBy('s.studio')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['studioId']] = (int) $row['nb'];
        }
        return $counts;
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
