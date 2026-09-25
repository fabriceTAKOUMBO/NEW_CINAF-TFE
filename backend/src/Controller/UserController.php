<?php
namespace App\Controller;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Espace « Mon compte » : l'utilisateur connecté consulte, modifie, exporte
 * ou supprime SON propre compte (préfixe `/api/users`).
 *
 * Endpoints :
 *  - GET    /{id}         profil
 *  - PATCH  /{id}         modification du prénom et du nom
 *  - DELETE /{id}         suppression du compte (RGPD, droit à l'effacement)
 *  - GET    /{id}/export  export des données personnelles (RGPD, droit d'accès)
 *
 * Accès : JWT exigé (`#[IsGranted]` ci-dessous + `access_control` de security.yaml).
 * Protection IDOR (Insecure Direct Object Reference) : chaque action compare
 * l'utilisateur ciblé par `{id}` à l'utilisateur connecté et renvoie 403 s'ils
 * diffèrent. Même un admin ne peut pas agir ici sur le compte d'un autre : il
 * passe par `/api/admin/users` (AdminUserController).
 */
#[Route('/api/users')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class UserController extends AbstractController
{
    public function __construct(
        private UserRepository $userRepo,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
    ) {}

    /**
     * Renvoie le profil de l'utilisateur, uniquement s'il s'agit du compte connecté.
     *
     * @param string $id UUID de l'utilisateur (un UUID mal formé n'est pas intercepté :
     *                   Uuid::fromString() lève alors une exception)
     *
     * @return JsonResponse 200 `User::toArray()` ; 404 utilisateur introuvable ;
     *                      403 s'il ne s'agit pas du compte connecté
     */
    #[Route('/{id}', name: 'user_get', methods: ['GET'])]
    public function getProfile(string $id): JsonResponse
    {
        $user = $this->userRepo->findOneBy(['id' => Uuid::fromString($id)]);

        if (!$user) {
            return $this->json(['message' => 'Utilisateur introuvable.'], 404);
        }

        // Seul l'utilisateur lui-même peut voir son profil
        // (anti-IDOR : l'identifiant comparé est l'email, unique en base).
        if ($user->getUserIdentifier() !== $this->getUser()->getUserIdentifier()) {
            return $this->json(['message' => 'Accès refusé.'], 403);
        }

        return $this->json($user->toArray());
    }

    /**
     * Met à jour le prénom et/ou le nom de l'utilisateur connecté.
     *
     * Seuls `firstName` et `lastName` sont pris en compte ; tout autre champ du
     * corps JSON (email, rôles, mot de passe…) est ignoré, ce qui empêche un
     * utilisateur de s'attribuer un rôle. Les changements d'email et de rôles
     * relèvent de l'administration.
     *
     * @return JsonResponse 200 `User::toArray()` à jour ; 404 introuvable ; 403 autre compte
     */
    #[Route('/{id}', name: 'user_patch', methods: ['PATCH'])]
    public function updateProfile(string $id, Request $request): JsonResponse
    {
        $user = $this->userRepo->findOneBy(['id' => Uuid::fromString($id)]);

        if (!$user) {
            return $this->json(['message' => 'Utilisateur introuvable.'], 404);
        }
        if ($user->getUserIdentifier() !== $this->getUser()->getUserIdentifier()) {
            return $this->json(['message' => 'Accès refusé.'], 403);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['firstName'])) {
            $user->setFirstName($data['firstName']);
        }
        if (isset($data['lastName'])) {
            $user->setLastName($data['lastName']);
        }

        $this->em->flush();

        return $this->json($user->toArray());
    }

    /**
     * Supprime définitivement le compte de l'utilisateur connecté (droit à
     * l'effacement RGPD).
     *
     * Suppression physique (pas de désactivation). Les abonnements de
     * l'utilisateur disparaissent avec lui (clé étrangère `ON DELETE CASCADE`).
     * En revanche, le propriétaire d'un studio ne peut pas être supprimé tant
     * que le studio existe (`studio.owner_id` en `ON DELETE RESTRICT`) : la base
     * refuse alors l'opération.
     *
     * @return JsonResponse 204 ; 404 introuvable ; 403 autre compte
     */
    #[Route('/{id}', name: 'user_delete', methods: ['DELETE'])]
    public function deleteAccount(string $id): JsonResponse
    {
        $user = $this->userRepo->findOneBy(['id' => Uuid::fromString($id)]);

        if (!$user) {
            return $this->json(['message' => 'Utilisateur introuvable.'], 404);
        }
        if ($user->getUserIdentifier() !== $this->getUser()->getUserIdentifier()) {
            return $this->json(['message' => 'Accès refusé.'], 403);
        }

        $this->em->remove($user);
        $this->em->flush();

        return new JsonResponse(null, 204);
    }

    /**
     * Exporte les données personnelles de l'utilisateur connecté (droit d'accès
     * et portabilité RGPD) au format JSON.
     *
     * L'export contient le profil (`User::toArray()`) complété du consentement
     * RGPD et de la date de dernière modification. Il n'inclut ni les
     * abonnements, ni le studio éventuel.
     *
     * @return JsonResponse 200 `{export_date, user}` ; 404 introuvable ; 403 autre compte
     */
    #[Route('/{id}/export', name: 'user_export', methods: ['GET'])]
    public function exportData(string $id): JsonResponse
    {
        $user = $this->userRepo->findOneBy(['id' => Uuid::fromString($id)]);

        if (!$user) {
            return $this->json(['message' => 'Utilisateur introuvable.'], 404);
        }
        if ($user->getUserIdentifier() !== $this->getUser()->getUserIdentifier()) {
            return $this->json(['message' => 'Accès refusé.'], 403);
        }

        return $this->json([
            'export_date' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'user' => array_merge($user->toArray(), [
                'consentRgpd' => $user->getConsentRgpd(),
                'updatedAt' => $user->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            ]),
        ]);
    }
}
