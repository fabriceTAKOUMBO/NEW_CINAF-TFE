<?php
namespace App\Repository;

use App\Entity\Studio;
use App\Entity\WithdrawalRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<WithdrawalRequest>
 */
class WithdrawalRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WithdrawalRequest::class);
    }

    /**
     * @return WithdrawalRequest[]
     */
    public function findPending(): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.status = :pending')
            ->setParameter('pending', WithdrawalRequest::STATUS_PENDING)
            ->orderBy('w.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the active PENDING request for a given target (film|serie + UUID),
     * or null if none exists. Used to enforce the partial unique index in
     * application code as well.
     */
    public function findActivePendingFor(string $targetType, Uuid $targetId): ?WithdrawalRequest
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.targetType = :type')
            ->andWhere('w.targetId = :id')
            ->andWhere('w.status = :pending')
            ->setParameter('type', $targetType)
            ->setParameter('id', $targetId, 'uuid')
            ->setParameter('pending', WithdrawalRequest::STATUS_PENDING)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countByStudioAndStatus(Studio $studio, string $status): int
    {
        return (int) $this->createQueryBuilder('w')
            ->select('COUNT(w.id)')
            ->andWhere('w.studio = :studio')
            ->andWhere('w.status = :status')
            ->setParameter('studio', $studio)
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Liste paginée admin de toutes les demandes, filtre optionnel par status.
     *
     * @return WithdrawalRequest[]
     */
    public function findAllPaginated(?string $status, int $page, int $limit): array
    {
        $qb = $this->buildAllPaginatedQuery($status)
            ->orderBy('w.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    public function countAll(?string $status): int
    {
        $qb = $this->buildAllPaginatedQuery($status)->select('COUNT(w.id)');

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function buildAllPaginatedQuery(?string $status): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('w');

        if ($status !== null && $status !== '') {
            $qb->andWhere('w.status = :status')->setParameter('status', $status);
        }

        return $qb;
    }
}
