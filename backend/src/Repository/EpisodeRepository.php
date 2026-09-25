<?php
namespace App\Repository;

use App\Entity\Episode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Accès aux épisodes de série (Episode). Aucune requête spécifique : les
 * appelants (EpisodeController, StudioSerieController, StudioUploadController)
 * se contentent des méthodes héritées find() / findOneBy().
 */
class EpisodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Episode::class);
    }
}
