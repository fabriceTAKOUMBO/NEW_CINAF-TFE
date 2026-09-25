<?php
namespace App\Repository;

use App\Entity\Genre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Accès au référentiel des genres (Genre).
 *
 * La liste publique GET /api/genres utilise findBy() hérité
 * (CatalogueReferenceController) ; findBySlug() et findByName() sont
 * appelées en repli par FilmController / SerieController::attachRelations()
 * (PATCH admin /api/films/{id} et /api/series/{id}), après une recherche par UUID.
 */
class GenreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Genre::class);
    }

    /** Genre dont le slug vaut exactement `$slug`, ou null. */
    public function findBySlug(string $slug): ?Genre
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /** Genre dont le nom vaut exactement `$name` (comparaison sensible à la casse), ou null. */
    public function findByName(string $name): ?Genre
    {
        return $this->findOneBy(['name' => $name]);
    }
}
