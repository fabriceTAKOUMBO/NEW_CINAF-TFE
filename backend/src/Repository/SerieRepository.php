<?php
namespace App\Repository;

use App\Entity\Serie;
use App\Entity\Studio;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Requêtes sur les séries ; même organisation que FilmRepository :
 *  - public (/api/series, catalogue discover, chaîne publique d'un studio) :
 *    toujours appelées avec le statut PUBLISHED ;
 *  - admin (/api/admin/series) : findAllPaginated() / countAll(), tous statuts ;
 *  - studio (/api/studio/series, tableau de bord) : findByStudioPaginated(),
 *    countByStudio*().
 *
 * Convention : un paramètre `$status` null ou '' signifie « pas de filtre de
 * statut ». Les pages sont numérotées à partir de 1.
 */
class SerieRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Serie::class);
    }

    /**
     * Liste paginée admin : toutes les séries, filtres optionnels.
     *
     * Filtres : statut, studio, recherche insensible à la casse sur le titre.
     * Aussi utilisée par le catalogue public (source `db`) avec PUBLISHED.
     * Tri : plus récentes d'abord.
     *
     * @return Serie[]
     */
    public function findAllPaginated(
        ?string $status,
        ?Uuid $studioId,
        ?string $search,
        int $page,
        int $limit,
    ): array {
        $qb = $this->buildAllPaginatedQuery($status, $studioId, $search)
            ->orderBy('s.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    /** Nombre total de séries correspondant aux mêmes filtres que findAllPaginated(). */
    public function countAll(?string $status, ?Uuid $studioId, ?string $search): int
    {
        $qb = $this->buildAllPaginatedQuery($status, $studioId, $search)
            ->select('COUNT(s.id)');

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Requête commune à findAllPaginated() et countAll() : garantit que la
     * liste et son total appliquent exactement les mêmes filtres.
     */
    private function buildAllPaginatedQuery(?string $status, ?Uuid $studioId, ?string $search): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('s');

        if ($status !== null && $status !== '') {
            $qb->andWhere('s.status = :status')->setParameter('status', $status);
        }
        if ($studioId !== null) {
            $qb->andWhere('s.studio = :studioId')->setParameter('studioId', $studioId, 'uuid');
        }
        if ($search !== null && $search !== '') {
            $qb->andWhere('LOWER(s.title) LIKE :q')
               ->setParameter('q', '%' . strtolower($search) . '%');
        }

        return $qb;
    }

    /**
     * Liste paginée des séries d'un studio, optionnellement filtrée par statut.
     *
     * Sert au module Studio (tous statuts) et à la chaîne publique du studio
     * (avec PUBLISHED). Tri : plus récentes d'abord.
     *
     * @return array{data: Serie[], total: int, page: int, limit: int}
     */
    public function findByStudioPaginated(Studio $studio, ?string $status, int $page, int $limit): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.studio = :studio')
            ->setParameter('studio', $studio)
            ->orderBy('s.createdAt', 'DESC');

        if ($status !== null && $status !== '') {
            $qb->andWhere('s.status = :status')->setParameter('status', $status);
        }

        // Total calculé sur une copie sans ORDER BY, avant la pagination.
        $countQb = clone $qb;
        $countQb->resetDQLPart('orderBy');
        $total = (int) $countQb->select('COUNT(s.id)')->getQuery()->getSingleScalarResult();

        $items = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['data' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    /** Nombre de séries d'un studio dans un statut donné (tableau de bord studio, fiche publique). */
    public function countByStudioAndStatus(Studio $studio, string $status): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.studio = :studio')
            ->andWhere('s.status = :status')
            ->setParameter('studio', $studio)
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Nombre de séries par studio pour un statut, en UNE requête
     * (cf. FilmRepository::countByStudiosAndStatus).
     *
     * @param  list<Studio>       $studios
     * @return array<string, int> id du studio (RFC 4122) => nombre
     */
    public function countByStudiosAndStatus(array $studios, string $status): array
    {
        if ($studios === []) {
            return [];
        }
        // Un studio sans série dans ce statut est absent du résultat (défaut 0 côté appelant).
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.studio) AS studioId, COUNT(s.id) AS nb')
            ->andWhere('s.studio IN (:studios)')
            ->andWhere('s.status = :status')
            ->setParameter('studios', $studios)
            ->setParameter('status', $status)
            ->groupBy('s.studio')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['studioId']] = (int) $row['nb'];
        }
        return $counts;
    }

    /** Nombre total de séries d'un studio, tous statuts confondus (tableau de bord studio). */
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
     * Liste paginée du catalogue public GET /api/series (plus récentes d'abord).
     *
     * @return array{data: Serie[], total: int, page: int, limit: int}
     */
    public function findPaginated(int $page = 1, int $limit = 30, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->orderBy('s.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        if ($status !== null && $status !== '') {
            $qb->andWhere('s.status = :status')->setParameter('status', $status);
        }

        $items = $qb->getQuery()->getResult();
        $total = $status === null || $status === ''
            ? $this->count([])
            : $this->count(['status' => $status]);

        return ['data' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    /**
     * Recherche du catalogue public GET /api/series/search.
     *
     * Texte libre sur titre + synopsis (insensible à la casse), combiné aux
     * filtres optionnels. Tri : plus récentes d'abord. Même limite que
     * FilmRepository::search() : la jointure sur les genres peut réduire le
     * nombre de séries distinctes par page (total, lui, exact).
     *
     * @param array<string, mixed> $filters Clés reconnues : `genre` (nom ou slug), `year`.
     *
     * @return array{data: Serie[], total: int, page: int, limit: int}
     */
    public function search(?string $q, array $filters = [], int $page = 1, int $limit = 30, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('s')->leftJoin('s.genres', 'g');
        if ($q) {
            $qb->andWhere('LOWER(s.title) LIKE :q OR LOWER(s.synopsis) LIKE :q')
               ->setParameter('q', '%' . strtolower($q) . '%');
        }
        if (!empty($filters['genre'])) {
            $qb->andWhere('g.name = :genre OR g.slug = :genre')->setParameter('genre', $filters['genre']);
        }
        if (!empty($filters['year'])) {
            $qb->andWhere('s.year = :year')->setParameter('year', (int) $filters['year']);
        }
        if ($status !== null && $status !== '') {
            $qb->andWhere('s.status = :status')->setParameter('status', $status);
        }

        // DISTINCT : une série jointe à plusieurs genres ne doit compter qu'une fois.
        $total = (int) (clone $qb)->select('COUNT(DISTINCT s.id)')->getQuery()->getSingleScalarResult();

        $items = $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return ['data' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }
}
