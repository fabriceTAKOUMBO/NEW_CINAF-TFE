<?php
namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }
        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function save(User $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(User $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Paginated list of users with optional search (email/firstName/lastName LIKE insensible)
     * and optional role filter (PostgreSQL jsonb containment).
     *
     * @return array{data: User[], total: int, page: int, limit: int}
     */
    public function findPaginated(int $page, int $limit, ?string $search = null, ?string $role = null): array
    {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));

        $qb = $this->createQueryBuilder('u');

        if ($search !== null && trim($search) !== '') {
            $needle = '%' . strtolower(trim($search)) . '%';
            $qb->andWhere('LOWER(u.email) LIKE :needle OR LOWER(u.firstName) LIKE :needle OR LOWER(u.lastName) LIKE :needle')
                ->setParameter('needle', $needle);
        }

        if ($role !== null && $role !== '') {
            // PostgreSQL type `json` refuse LIKE, et Doctrine DQL ne supporte pas CAST.
            // On pré-résout les IDs via SQL natif (CAST ... AS text) puis on filtre le QB par IN.
            $conn = $this->getEntityManager()->getConnection();
            $sql = 'SELECT id FROM "user" WHERE CAST(roles AS text) LIKE :roleNeedle';
            $ids = $conn->fetchFirstColumn($sql, [
                'roleNeedle' => '%"' . $role . '"%',
            ]);

            if (empty($ids)) {
                return ['data' => [], 'total' => 0, 'page' => $page, 'limit' => $limit];
            }

            $qb->andWhere('u.id IN (:roleIds)')
                ->setParameter('roleIds', $ids);
        }

        // Total count (cloner avant pagination)
        $countQb = clone $qb;
        $countQb->select('COUNT(u.id)');
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        $qb->orderBy('u.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        $data = $qb->getQuery()->getResult();

        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }
}
