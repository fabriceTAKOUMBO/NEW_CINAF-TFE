<?php
namespace App\Repository;

use App\Entity\FeaturedContent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Accès aux mises en avant éditoriales (« à la une »). Fournit findActive(),
 * lue par GET /api/films/featured (FilmController::featuredList()).
 */
class FeaturedContentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FeaturedContent::class);
    }

    /**
     * Mises en avant en cours à l'instant présent, triées par `position`
     * croissante : interrupteur `active` à true, période commencée et non
     * terminée (une date de fin nulle signifie « sans fin »).
     *
     * Renvoie films ET séries mis en avant, sans filtre de statut : c'est
     * l'appelant qui ne garde que les films PUBLISHED.
     *
     * @return FeaturedContent[]
     */
    public function findActive(): array
    {
        $now = new \DateTimeImmutable();
        return $this->createQueryBuilder('fc')
            ->where('fc.active = :active')
            ->andWhere('fc.startDate <= :now')
            // andWhere() met cette expression OR entre parenthèses : elle
            // reste combinée en ET avec les deux conditions précédentes.
            ->andWhere('fc.endDate IS NULL OR fc.endDate >= :now')
            ->setParameter('active', true)
            ->setParameter('now', $now)
            ->orderBy('fc.position', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
