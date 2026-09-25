<?php
namespace App\Repository;

use App\Entity\Season;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Accès aux saisons de série (Season). Aucune requête spécifique : les
 * appelants (SerieController, StudioSerieController) utilisent find() /
 * findOneBy() hérités, par exemple (serie, number) pour retrouver une saison.
 */
class SeasonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Season::class);
    }
}
