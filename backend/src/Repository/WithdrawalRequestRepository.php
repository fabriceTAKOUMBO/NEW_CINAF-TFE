<?php
namespace App\Repository;

use App\Entity\Studio;
use App\Entity\WithdrawalRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Accès aux demandes de retrait (WithdrawalRequest) :
 *  - findActivePendingFor() : contrôle applicatif « une seule demande PENDING
 *    par contenu » (ContentLifecycleService::requestWithdrawal(), 409) ;
 *  - countByStudioAndStatus() : compteur `pendingWithdrawals` du tableau de
 *    bord GET /api/studio/me ;
 *  - findAllPaginated() / countAll() : liste admin GET /api/admin/withdrawals.
 *
 * Convention : un `$status` null ou '' signifie « pas de filtre de statut ».
 *
 * @extends ServiceEntityRepository<WithdrawalRequest>
 */
class WithdrawalRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WithdrawalRequest::class);
    }

    /**
     * Toutes les demandes en attente, les plus anciennes d'abord (file
     * d'attente). Aucun appelant dans le code actuel : la liste admin passe par
     * findAllPaginated() avec le statut PENDING.
     *
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
     *
     * Renvoie la demande PENDING existante pour ce contenu (film ou série),
     * ou null : double applicatif de l'index unique partiel
     * `uniq_withdrawal_pending`, qui permet de répondre 409 proprement au lieu
     * d'une violation de contrainte en base.
     */
    public function findActivePendingFor(string $targetType, Uuid $targetId): ?WithdrawalRequest
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.targetType = :type')
            ->andWhere('w.targetId = :id')
            ->andWhere('w.status = :pending')
            ->setParameter('type', $targetType)
            // Type 'uuid' explicite : `targetId` est une colonne UUID simple
            // (référence polymorphe, sans association Doctrine).
            ->setParameter('id', $targetId, 'uuid')
            ->setParameter('pending', WithdrawalRequest::STATUS_PENDING)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Nombre de demandes d'un studio dans un statut donné (tableau de bord studio : PENDING). */
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
     * Tri : plus récentes d'abord ; pages numérotées à partir de 1.
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

    /** Nombre total de demandes correspondant au même filtre que findAllPaginated(). */
    public function countAll(?string $status): int
    {
        $qb = $this->buildAllPaginatedQuery($status)->select('COUNT(w.id)');

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Requête commune à findAllPaginated() et countAll() : la liste et son
     * total appliquent exactement le même filtre.
     */
    private function buildAllPaginatedQuery(?string $status): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('w');

        if ($status !== null && $status !== '') {
            $qb->andWhere('w.status = :status')->setParameter('status', $status);
        }

        return $qb;
    }
}
