<?php
namespace App\Repository;

use App\Entity\Country;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Accès au référentiel des pays (Country).
 *
 * La liste publique GET /api/countries utilise findBy() hérité
 * (CatalogueReferenceController) ; findByIsoCode() est appelée en repli par
 * FilmController / SerieController::attachRelations() (PATCH admin
 * /api/films/{id} et /api/series/{id}), après une recherche par UUID.
 */
class CountryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Country::class);
    }

    /** Pays dont le code ISO vaut exactement `$code` (comparaison sensible à la casse), ou null. */
    public function findByIsoCode(string $code): ?Country
    {
        return $this->findOneBy(['isoCode' => $code]);
    }
}
