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

/**
 * Gestion des comptes utilisateurs par l'administrateur (préfixe `/api/admin/users`).
 *
 * Accès : ROLE_ADMIN, exigé deux fois (`#[IsGranted]` ci-dessous + règle
 * `access_control` `^/api/admin` de security.yaml).
 *  - GET    /export        export CSV de tous les utilisateurs
 *  - GET    /              liste paginée (recherche, filtre par rôle, abonnement actif)
 *  - GET    /{id}          fiche d'un utilisateur
 *  - PATCH  /{id}/suspend  suspension (connexion refusée)
 *  - PATCH  /{id}/activate levée de la suspension
 *  - PATCH  /{id}/role     remplacement des rôles
 *  - PATCH  /{id}          email, prénom, nom, email vérifié
 *  - DELETE /{id}          suppression définitive
 *
 * Auto-protection : un admin ne peut ni se suspendre, ni se retirer ROLE_ADMIN,
 * ni supprimer son propre compte (cf. isSelf()), pour ne pas se priver
 * lui-même de l'accès au back-office.
 * Les abonnements d'un utilisateur sont gérés par AdminSubscriptionController.
 */
#[Route('/api/admin/users')]
#[IsGranted('ROLE_ADMIN')]
class AdminUserController extends AbstractController
{
    /** Rôles qu'un admin peut attribuer ou utiliser comme filtre de liste. */
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
     * Exporte TOUS les utilisateurs au format CSV (sans pagination ni filtre),
     * du plus récent au plus ancien.
     * L'ordre des routes importe : /export doit être déclaré AVANT /{id}.
     * (Sinon « export » serait capturé comme valeur de `{id}` par getOne().)
     *
     * Le CSV est construit en mémoire (flux `php://temp`) puis renvoyé dans une
     * `Response` classique plutôt qu'une `StreamedResponse` : le client de test
     * (BrowserKit) ne capture pas le contenu d'une réponse streamée.
     * Rôles séparés par `|`, booléens en 1/0, dates au format ATOM.
     *
     * @return Response 200 `text/csv` en pièce jointe `users-export.csv`
     */
    #[Route('/export', name: 'admin_users_export', methods: ['GET'])]
    public function export(): Response
    {
        $users = $this->userRepo->findBy([], ['createdAt' => 'DESC']);

        $handle = fopen('php://temp', 'r+');
        // BOM UTF-8 pour Excel
        // (sans lui, Excel lit le fichier dans l'encodage local et casse les accents).
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

    /**
     * Liste paginée des utilisateurs avec recherche et filtre rôle.
     *
     * Paramètres de requête : `page` (défaut 1), `limit` (défaut 20, borné à
     * 1..100), `search` (email, prénom ou nom, insensible à la casse), `role`
     * (un des ALLOWED_ROLES ; filtre sur les rôles enregistrés en base, pas sur
     * les rôles hérités). Tri : inscription la plus récente d'abord.
     * Chaque ligne est enrichie de `currentSubscription` (abonnement actif ou null).
     *
     * @return JsonResponse 200 `{data, total, page, limit}` ; 400 si le rôle de filtre est inconnu
     */
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

    /**
     * Renvoie la fiche d'un utilisateur.
     *
     * Nommée `getOne()` et non `getUser()` : AbstractController possède déjà
     * `getUser(): ?UserInterface` (l'utilisateur connecté), qu'une méthode
     * `getUser(string $id)` redéfinirait avec une signature incompatible
     * (erreur fatale PHP) — et isSelf() en a besoin.
     *
     * @return JsonResponse 200 `User::toArray()` ; 400 UUID mal formé ; 404 introuvable
     */
    #[Route('/{id}', name: 'admin_users_get', methods: ['GET'])]
    public function getOne(string $id): JsonResponse
    {
        $user = $this->resolveUser($id);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return $this->json($user->toArray());
    }

    /**
     * Suspend un compte : `POST /api/auth/login` le refuse ensuite (403).
     *
     * Les jetons déjà émis ne sont pas révoqués : l'access token en cours reste
     * valable jusqu'à son expiration et `/api/auth/refresh` ne vérifie pas la
     * suspension. Un admin ne peut pas se suspendre lui-même.
     *
     * @return JsonResponse 200 `User::toArray()` ; 400 si l'admin se cible lui-même ;
     *                      400/404 (cf. resolveUser())
     */
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

    /**
     * Lève la suspension d'un compte (l'utilisateur peut de nouveau se connecter).
     *
     * @return JsonResponse 200 `User::toArray()` ; 400/404 (cf. resolveUser())
     */
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

    /**
     * Remplace la liste des rôles d'un utilisateur (pas d'ajout incrémental).
     *
     * Corps JSON : `{roles: [...]}`, non vide, chaque rôle devant figurer dans
     * ALLOWED_ROLES (doublons retirés). ROLE_USER est de toute façon ajouté par
     * User::getRoles(). Attribuer ROLE_CREATEUR ne crée pas de studio : le
     * parcours normal est `POST /api/studio/onboarding`.
     *
     * @return JsonResponse 200 `User::toArray()` ; 400 champ `roles` absent, vide ou
     *                      contenant un rôle inconnu, ou admin qui se retire ROLE_ADMIN ;
     *                      400/404 (cf. resolveUser())
     */
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
     * Les rôles ont leur propre endpoint dédié (`PATCH /{id}/role`) pour des raisons
     * de sécurité ; le mot de passe n'est modifiable par aucun endpoint admin
     * (l'utilisateur passe par la réinitialisation par email).
     *
     * Mise à jour partielle : seuls les champs présents dans le corps JSON sont
     * traités. Changer l'email coupe de fait les sessions de l'utilisateur : son
     * JWT et ses refresh tokens référencent l'ancien email, qui ne correspond
     * plus à aucun compte.
     *
     * @return JsonResponse 200 `User::toArray()` ; 400 JSON invalide, email invalide, prénom
     *                      ou nom vide ; 409 email déjà utilisé ; 400/404 (cf. resolveUser())
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
     * Ses abonnements sont supprimés en cascade (FK `ON DELETE CASCADE`). Ses refresh
     * tokens, eux, restent en base : la table `refresh_tokens` ne stocke que l'email,
     * sans clé étrangère vers `user` ; ils deviennent inutilisables car
     * `/api/auth/refresh` ne retrouve plus de compte pour cet email.
     * La base refuse la suppression du propriétaire d'un studio (FK `ON DELETE RESTRICT`)
     * ou d'un utilisateur référencé par une demande de retrait.
     *
     * @return JsonResponse 204 ; 400 si l'admin se cible lui-même ; 400/404 (cf. resolveUser())
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

    /**
     * Vrai si l'utilisateur ciblé est l'admin connecté (comparaison par email,
     * identifiant unique). Sert à l'auto-protection de suspend(), updateRole() et delete().
     */
    private function isSelf(User $target): bool
    {
        $current = $this->getUser();
        if (!$current instanceof User) {
            return false;
        }

        return $current->getUserIdentifier() === $target->getUserIdentifier();
    }
}
