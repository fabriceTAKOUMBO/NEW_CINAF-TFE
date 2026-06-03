<?php

namespace App\Admin\Controller;

use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Service\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/admin/users')]
#[IsGranted('ROLE_ADMIN')]
class AdminUserController extends AbstractController
{
    private const ALLOWED_ROLES = [
        'ROLE_USER',
        'ROLE_ABONNE',
        'ROLE_CREATEUR',
        'ROLE_MODERATEUR',
        'ROLE_ADMIN',
    ];

    public function __construct(
        private UserRepository $userRepo,
        private EntityManagerInterface $em,
        private SubscriptionRepository $subRepo,
        private SubscriptionService $subService,
    ) {
    }

    /**
     * Liste paginée des utilisateurs avec recherche et filtre rôle.
     * L'ordre des routes importe : /export doit être déclaré AVANT /{id}.
     */
    #[Route('/export', name: 'admin_users_export', methods: ['GET'])]
    public function export(): Response
    {
        $users = $this->userRepo->findBy([], ['createdAt' => 'DESC']);

        $handle = fopen('php://temp', 'r+');
        // BOM UTF-8 pour Excel
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, [
            'id',
            'email',
            'firstName',
            'lastName',
            'roles',
            'isVerified',
            'isSuspended',
            'createdAt',
        ]);
        foreach ($users as $user) {
            fputcsv($handle, [
                $user->getId()->toRfc4122(),
                $user->getEmail(),
                $user->getFirstName(),
                $user->getLastName(),
                implode('|', $user->getRoles()),
                $user->isVerified() ? '1' : '0',
                $user->isSuspended() ? '1' : '0',
                $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ]);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="users-export.csv"');

        return $response;
    }

    #[Route('', name: 'admin_users_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = (int) $request->query->get('limit', 20);
        $limit = max(1, min(100, $limit));
        $search = $request->query->get('search');
        $role = $request->query->get('role');

        if ($role !== null && $role !== '' && !\in_array($role, self::ALLOWED_ROLES, true)) {
            return $this->json(['message' => 'Rôle de filtre invalide.'], 400);
        }

        $result = $this->userRepo->findPaginated($page, $limit, $search, $role);

        // Enrichissement : pour chaque user de la page, joindre le résumé de
        // l'abonnement actif (ou null). Une seule requête batch via repository.
        $userIds = array_map(fn (User $u) => $u->getId(), $result['data']);
        $subsByUser = $this->subRepo->findCurrentActiveByUserIds($userIds);

        $data = array_map(function (User $u) use ($subsByUser) {
            $row = $u->toArray();
            $sub = $subsByUser[$u->getId()->toRfc4122()] ?? null;
            $row['currentSubscription'] = $sub === null ? null : [
                'id' => $sub->getId()->toRfc4122(),
                'planName' => $sub->getPlan()->getName(),
                'status' => $sub->getStatus(),
                'startsAt' => $sub->getStartsAt()->format(\DateTimeInterface::ATOM),
                'endsAt' => $sub->getEndsAt()?->format(\DateTimeInterface::ATOM),
                'isCurrentlyActive' => $sub->isCurrentlyActive(),
            ];
            return $row;
        }, $result['data']);

        return $this->json([
            'data' => $data,
            'total' => $result['total'],
            'page' => $result['page'],
            'limit' => $result['limit'],
        ]);
    }

    #[Route('/{id}', name: 'admin_users_get', methods: ['GET'])]
    public function getOne(string $id): JsonResponse
    {
        $user = $this->resolveUser($id);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return $this->json($user->toArray());
    }

    #[Route('/{id}/suspend', name: 'admin_users_suspend', methods: ['PATCH'])]
    public function suspend(string $id): JsonResponse
    {
        $target = $this->resolveUser($id);
        if ($target instanceof JsonResponse) {
            return $target;
        }

        if ($this->isSelf($target)) {
            return $this->json(['message' => 'Vous ne pouvez pas suspendre votre propre compte.'], 400);
        }

        $target->setIsSuspended(true);
        $this->em->flush();

        return $this->json($target->toArray());
    }

    #[Route('/{id}/activate', name: 'admin_users_activate', methods: ['PATCH'])]
    public function activate(string $id): JsonResponse
    {
        $target = $this->resolveUser($id);
        if ($target instanceof JsonResponse) {
            return $target;
        }

        $target->setIsSuspended(false);
        $this->em->flush();

        return $this->json($target->toArray());
    }

    #[Route('/{id}/role', name: 'admin_users_role', methods: ['PATCH'])]
    public function updateRole(string $id, Request $request): JsonResponse
    {
        $target = $this->resolveUser($id);
        if ($target instanceof JsonResponse) {
            return $target;
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || !isset($data['roles']) || !\is_array($data['roles'])) {
            return $this->json(['message' => 'Le champ "roles" (array) est requis.'], 400);
        }

        $roles = array_values(array_unique(array_map('strval', $data['roles'])));
        if ($roles === []) {
            return $this->json(['message' => 'Au moins un rôle est requis.'], 400);
        }

        foreach ($roles as $role) {
            if (!\in_array($role, self::ALLOWED_ROLES, true)) {
                return $this->json(['message' => sprintf('Rôle invalide : "%s".', $role)], 400);
            }
        }

        // Protection self-modification : l'admin ne peut pas se retirer ROLE_ADMIN lui-même.
        if ($this->isSelf($target) && !\in_array('ROLE_ADMIN', $roles, true)) {
            return $this->json(['message' => 'Vous ne pouvez pas retirer votre propre rôle ROLE_ADMIN.'], 400);
        }

        $target->setRoles($roles);
        $this->em->flush();

        return $this->json($target->toArray());
    }

    /**
     * Modifie les informations générales d'un utilisateur (email, prénom, nom, isVerified).
     * Le mot de passe et les rôles ont leur propre endpoint dédié pour des raisons de sécurité.
     */
    #[Route('/{id}', name: 'admin_users_update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $target = $this->resolveUser($id);
        if ($target instanceof JsonResponse) {
            return $target;
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['message' => 'Corps JSON invalide.'], 400);
        }

        if (\array_key_exists('email', $data)) {
            $email = trim((string) $data['email']);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->json(['message' => 'Email invalide.'], 400);
            }
            if ($email !== $target->getEmail()) {
                $existing = $this->userRepo->findOneBy(['email' => $email]);
                if ($existing && $existing->getId()->toRfc4122() !== $target->getId()->toRfc4122()) {
                    return $this->json(['message' => 'Cet email est déjà utilisé.'], 409);
                }
                $target->setEmail($email);
            }
        }

        if (\array_key_exists('firstName', $data)) {
            $firstName = trim((string) $data['firstName']);
            if ($firstName === '') {
                return $this->json(['message' => 'Le prénom ne peut pas être vide.'], 400);
            }
            $target->setFirstName($firstName);
        }

        if (\array_key_exists('lastName', $data)) {
            $lastName = trim((string) $data['lastName']);
            if ($lastName === '') {
                return $this->json(['message' => 'Le nom ne peut pas être vide.'], 400);
            }
            $target->setLastName($lastName);
        }

        if (\array_key_exists('isVerified', $data)) {
            $target->setIsVerified((bool) $data['isVerified']);
        }

        $this->em->flush();

        return $this->json($target->toArray());
    }

    /**
     * Supprime définitivement un utilisateur. L'admin ne peut pas se supprimer lui-même.
     * Les refresh tokens liés sont supprimés en cascade par la contrainte FK Doctrine.
     */
    #[Route('/{id}', name: 'admin_users_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $target = $this->resolveUser($id);
        if ($target instanceof JsonResponse) {
            return $target;
        }

        if ($this->isSelf($target)) {
            return $this->json(['message' => 'Vous ne pouvez pas supprimer votre propre compte.'], 400);
        }

        $this->em->remove($target);
        $this->em->flush();

        return $this->json(null, 204);
    }

    /**
     * Parse l'UUID, charge l'utilisateur ou renvoie une JsonResponse d'erreur (400/404).
     */
    private function resolveUser(string $id): User|JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            return $this->json(['message' => 'Identifiant invalide.'], 400);
        }

        $user = $this->userRepo->findOneBy(['id' => $uuid]);
        if (!$user) {
            return $this->json(['message' => 'Utilisateur introuvable.'], 404);
        }

        return $user;
    }

    private function isSelf(User $target): bool
    {
        $current = $this->getUser();
        if (!$current instanceof User) {
            return false;
        }

        return $current->getUserIdentifier() === $target->getUserIdentifier();
    }
}
