<?php
namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * Accès aux comptes utilisateurs.
 *
 * Fournit la liste paginée de l'administration (findPaginated(), avec son
 * filtre de rôle en SQL natif) et implémente PasswordUpgraderInterface.
 * La recherche par email passe par findOneBy() hérité (connexion, refresh,
 * mot de passe oublié).
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Contrat PasswordUpgraderInterface : enregistre le nouveau hash quand
     * Symfony juge l'ancien obsolète (changement d'algorithme ou de coût).
     *
     * Appelée par les authentificateurs Symfony à mot de passe ; le login
     * CINAF étant codé à la main dans AuthController (pas de `json_login`),
     * elle n'est pas déclenchée automatiquement à ce jour.
     *
     * @throws UnsupportedUserException si l'utilisateur n'est pas un App\Entity\User
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }
        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /** Persiste l'utilisateur ; n'écrit en base immédiatement que si `$flush` vaut true. */
    public function save(User $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** Programme la suppression de l'utilisateur ; exécutée immédiatement seulement si `$flush` vaut true. */
    public function remove(User $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Paginated list of users with optional search (email/firstName/lastName LIKE insensible)
     * and optional role filter (pré-requête SQL native sur la colonne `json`,
     * voir le commentaire en ligne).
     *
     * Utilisée par la liste admin GET /api/admin/users. Page ramenée à ≥ 1,
     * limite bornée à [1, 100], tri : comptes les plus récents d'abord.
     * Le filtre de rôle porte sur les rôles STOCKÉS en base : ni le ROLE_USER
     * implicite ajouté par User::getRoles(), ni les rôles hérités de la
     * hiérarchie ne sont pris en compte.
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
            // Le OR est mis entre parenthèses par andWhere() : il reste combiné
            // en ET avec le filtre de rôle ci-dessous.
            $qb->andWhere('LOWER(u.email) LIKE :needle OR LOWER(u.firstName) LIKE :needle OR LOWER(u.lastName) LIKE :needle')
                ->setParameter('needle', $needle);
        }

        if ($role !== null && $role !== '') {
            // PostgreSQL type `json` refuse LIKE, et Doctrine DQL ne supporte pas CAST.
            // On pré-résout les IDs via SQL natif (CAST ... AS text) puis on filtre le QB par IN.
            // Piège connu : ne pas « simplifier » en un LIKE DQL sur u.roles, qui
            // échoue sur PostgreSQL. Le rôle est cherché entre guillemets
            // ("ROLE_ADMIN") pour cibler un élément exact du tableau JSON sérialisé ;
            // la valeur est un paramètre lié, donc sans risque d'injection SQL.
            $conn = $this->getEntityManager()->getConnection();
            $sql = 'SELECT id FROM "user" WHERE CAST(roles AS text) LIKE :roleNeedle';
            $ids = $conn->fetchFirstColumn($sql, [
                'roleNeedle' => '%"' . $role . '"%',
            ]);

            // Personne n'a ce rôle : réponse vide immédiate, sans requête DQL.
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
