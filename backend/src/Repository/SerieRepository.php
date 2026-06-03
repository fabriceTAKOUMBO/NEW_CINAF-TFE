<?php
namespace App\Repository;

use App\Entity\Serie;
use App\Entity\Studio;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class SerieRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Serie::class);
    }

    /**
     * Liste paginée admin : toutes les séries, filtres optionnels.
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

    public function countAll(?string $status, ?Uuid $studioId, ?string $search): int
    {
        $qb = $this->buildAllPaginatedQuery($status, $studioId, $search)
            ->select('COUNT(s.id)');

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

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

    public function countByStudio(Studio $studio): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.studio = :studio')
            ->setParameter('studio', $studio)
            ->getQuery()
            ->getSingleScalarResult();
    }

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

        $total = (int) (clone $qb)->select('COUNT(DISTINCT s.id)')->getQuery()->getSingleScalarResult();

        $items = $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return ['data' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }
}
