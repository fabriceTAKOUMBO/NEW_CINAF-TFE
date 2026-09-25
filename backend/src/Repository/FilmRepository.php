<?php
namespace App\Repository;

use App\Entity\Film;
use App\Entity\Studio;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class FilmRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Film::class);
    }

    /**
     * Liste paginée admin : tous les films, filtres optionnels (status,
     * studioId, recherche par titre).
     *
     * @return Film[]
     */
    public function findAllPaginated(
        ?string $status,
        ?Uuid $studioId,
        ?string $search,
        int $page,
        int $limit,
    ): array {
        $qb = $this->buildAllPaginatedQuery($status, $studioId, $search)
            ->orderBy('f.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    public function countAll(?string $status, ?Uuid $studioId, ?string $search): int
    {
        $qb = $this->buildAllPaginatedQuery($status, $studioId, $search)
            ->select('COUNT(f.id)');

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function buildAllPaginatedQuery(?string $status, ?Uuid $studioId, ?string $search): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('f');

        if ($status !== null && $status !== '') {
            $qb->andWhere('f.status = :status')->setParameter('status', $status);
        }
        if ($studioId !== null) {
            $qb->andWhere('f.studio = :studioId')->setParameter('studioId', $studioId, 'uuid');
        }
        if ($search !== null && $search !== '') {
            $qb->andWhere('LOWER(f.title) LIKE :q')
               ->setParameter('q', '%' . strtolower($search) . '%');
        }

        return $qb;
    }

    /**
     * Liste paginée des films d'un studio, optionnellement filtrée par status.
     *
     * @return array{data: Film[], total: int, page: int, limit: int}
     */
    public function findByStudioPaginated(Studio $studio, ?string $status, int $page, int $limit): array
    {
        $qb = $this->createQueryBuilder('f')
            ->andWhere('f.studio = :studio')
            ->setParameter('studio', $studio)
            ->orderBy('f.createdAt', 'DESC');

        if ($status !== null && $status !== '') {
            $qb->andWhere('f.status = :status')->setParameter('status', $status);
        }

        $countQb = clone $qb;
        $countQb->resetDQLPart('orderBy');
        $total = (int) $countQb->select('COUNT(f.id)')->getQuery()->getSingleScalarResult();

        $items = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['data' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    public function countByStudioAndStatus(Studio $studio, string $status): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->andWhere('f.studio = :studio')
            ->andWhere('f.status = :status')
            ->setParameter('studio', $studio)
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Nombre de films par studio pour un statut, en UNE requête (les listes
     * publiques affichaient un COUNT par studio : N+1).
     *
     * @param  list<Studio>       $studios
     * @return array<string, int> id du studio (RFC 4122) => nombre
     */
    public function countByStudiosAndStatus(array $studios, string $status): array
    {
        if ($studios === []) {
            return [];
        }
        $rows = $this->createQueryBuilder('f')
            ->select('IDENTITY(f.studio) AS studioId, COUNT(f.id) AS nb')
            ->andWhere('f.studio IN (:studios)')
            ->andWhere('f.status = :status')
            ->setParameter('studios', $studios)
            ->setParameter('status', $status)
            ->groupBy('f.studio')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['studioId']] = (int) $row['nb'];
        }
        return $counts;
    }

    public function countByStudio(Studio $studio): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->andWhere('f.studio = :studio')
            ->setParameter('studio', $studio)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findPaginated(int $page = 1, int $limit = 30, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('f')
            ->orderBy('f.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        if ($status !== null && $status !== '') {
            $qb->andWhere('f.status = :status')->setParameter('status', $status);
        }

        $items = $qb->getQuery()->getResult();
        $total = $status === null || $status === ''
            ? $this->count([])
            : $this->count(['status' => $status]);

        return ['data' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    public function search(?string $q, array $filters = [], int $page = 1, int $limit = 30, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('f')
            ->leftJoin('f.genres', 'g')
            ->leftJoin('f.countries', 'c');

        if ($q) {
            $qb->andWhere('LOWER(f.title) LIKE :q OR LOWER(f.synopsis) LIKE :q')
               ->setParameter('q', '%' . strtolower($q) . '%');
        }
        if (!empty($filters['genre'])) {
            $qb->andWhere('g.name = :genre OR g.slug = :genre')
               ->setParameter('genre', $filters['genre']);
        }
        if (!empty($filters['year'])) {
            $qb->andWhere('f.year = :year')->setParameter('year', (int) $filters['year']);
        }
        if (!empty($filters['country'])) {
            $qb->andWhere('c.name = :country OR c.isoCode = :country')
               ->setParameter('country', $filters['country']);
        }
        if ($status !== null && $status !== '') {
            $qb->andWhere('f.status = :status')->setParameter('status', $status);
        }

        $total = (int) (clone $qb)->select('COUNT(DISTINCT f.id)')->getQuery()->getSingleScalarResult();

        $items = $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return ['data' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    public function findTrending(int $limit = 10, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('f')
            ->orderBy('f.views', 'DESC')
            ->setMaxResults($limit);

        // Filtrage du statut côté SQL : évite de récupérer N films puis d'en
        // filtrer une partie en mémoire (ce qui tronquait silencieusement la liste).
        if ($status !== null && $status !== '') {
            $qb->andWhere('f.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    public function findNew(int $limit = 10, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('f')
            ->orderBy('f.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($status !== null && $status !== '') {
            $qb->andWhere('f.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }
}
