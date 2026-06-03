<?php
namespace App\Repository;

use App\Entity\Studio;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Studio>
 */
class StudioRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Studio::class);
    }

    public function findBySlug(string $slug): ?Studio
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * @return Studio[]
     */
    public function findActiveOrdered(): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('s.slug', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countActive(): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.isActive = :active')
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Retourne les studios « publics » (actifs, validés, au moins un Film ou
     * Serie en `PUBLISHED`) paginés et triés alphabétiquement.
     *
     * Implémentation : la condition « au moins 1 contenu publié » est exprimée
     * via deux sous-requêtes `EXISTS` (films + séries) reliées par un `OR`.
     * Sur PostgreSQL, l'index `idx_film_status_studio` / `idx_serie_status_studio`
     * suffit à rendre le plan efficient.
     *
     * @return Studio[]
     */
    public function findPublicPaginated(int $page, int $limit): array
    {
        return $this->buildPublicQueryBuilder('s')
            ->orderBy('s.name', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte total des studios publics (utilisé pour `hydra:totalItems`).
     */
    public function countPublic(): int
    {
        return (int) $this->buildPublicQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Récupère un studio public par son slug.
     *
     * Retourne `null` si le studio n'existe pas, OU s'il existe mais ne
     * satisfait pas les critères de visibilité publique (inactif, non validé,
     * ou aucun contenu publié). Côté contrôleur, ces deux cas mappent vers
     * le même 404 — c'est volontaire pour ne pas leaker l'existence d'un
     * studio non public.
     */
    public function findPublicBySlug(string $slug): ?Studio
    {
        return $this->buildPublicQueryBuilder('s')
            ->andWhere('s.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Recherche un studio public par son nom (match partiel insensible à la casse).
     *
     * Utilise `LOWER(...) LIKE` pour rester portable (équivalent fonctionnel
     * d'ILIKE PostgreSQL sans dépendre d'une fonction spécifique au dialecte).
     *
     * @return Studio[]
     */
    public function searchPublicByName(string $q, int $limit = 20): array
    {
        $needle = '%' . strtolower($q) . '%';

        return $this->buildPublicQueryBuilder('s')
            ->andWhere('LOWER(s.name) LIKE :q')
            ->setParameter('q', $needle)
            ->orderBy('s.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Construit le QueryBuilder de base pour les requêtes « studio public » :
     * actif + validé + au moins un Film ou Serie en status='PUBLISHED'.
     *
     * Centralisé ici pour garantir que `findPublic*()` / `countPublic()` /
     * `searchPublicByName()` filtrent rigoureusement de la même façon.
     */
    private function buildPublicQueryBuilder(string $alias): QueryBuilder
    {
        return $this->createQueryBuilder($alias)
            ->andWhere(sprintf('%s.isActive = :active', $alias))
            ->andWhere(sprintf('%s.isValidated = :validated', $alias))
            ->andWhere(sprintf(
                'EXISTS (SELECT 1 FROM App\Entity\Film f WHERE f.studio = %1$s AND f.status = :publishedStatus) '
                . 'OR EXISTS (SELECT 1 FROM App\Entity\Serie se WHERE se.studio = %1$s AND se.status = :publishedStatus)',
                $alias
            ))
            ->setParameter('active', true)
            ->setParameter('validated', true)
            ->setParameter('publishedStatus', 'PUBLISHED');
    }
}
