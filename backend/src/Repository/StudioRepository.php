<?php
namespace App\Repository;

use App\Entity\Studio;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Accès aux studios, pour deux usages :
 *  - administration et import : findActiveOrdered() (liste admin
 *    GET /api/admin/studios, commande app:catalogue:import-bunny) ;
 *  - vitrine publique /api/studios (StudioPublicController) :
 *    findPublicPaginated(), countPublic(), findPublicBySlug() et
 *    searchPublicByName(), qui partagent le même filtre de visibilité
 *    (buildPublicQueryBuilder() : actif + validé + au moins un contenu publié).
 *
 * @extends ServiceEntityRepository<Studio>
 */
class StudioRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Studio::class);
    }

    /**
     * Studio par slug, SANS filtre de visibilité (actif, validé…), ou null.
     * Aucun appelant dans le code actuel : les pages publiques passent par
     * findPublicBySlug().
     */
    public function findBySlug(string $slug): ?Studio
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Studios actifs triés par slug croissant, sans pagination.
     *
     * Utilisée par la liste admin GET /api/admin/studios et par
     * app:catalogue:import-bunny, pour qui cet ordre stable détermine le
     * studio attribué à chaque œuvre importée (index crc32(slug de l'œuvre) % 10).
     *
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

    /** Nombre de studios actifs (aucun appelant dans le code actuel). */
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
     * Pages numérotées à partir de 1 (GET /api/studios).
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
            // Sous-requêtes corrélées : %1$s réinjecte l'alias du studio courant
            // dans les deux EXISTS. andWhere() met le OR entre parenthèses, il
            // reste donc combiné en ET avec les deux conditions ci-dessus.
            ->andWhere(sprintf(
                'EXISTS (SELECT 1 FROM App\Entity\Film f WHERE f.studio = %1$s AND f.status = :publishedStatus) '
                . 'OR EXISTS (SELECT 1 FROM App\Entity\Serie se WHERE se.studio = %1$s AND se.status = :publishedStatus)',
                $alias
            ))
            ->setParameter('active', true)
            ->setParameter('validated', true)
            // Valeur commune à Film::STATUS_PUBLISHED et Serie::STATUS_PUBLISHED.
            ->setParameter('publishedStatus', 'PUBLISHED');
    }
}
