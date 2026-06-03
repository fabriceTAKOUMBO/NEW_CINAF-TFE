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

#[Route('/api/users')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class UserController extends AbstractController
{
    public function __construct(
        private UserRepository $userRepo,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
    ) {}

    #[Route('/{id}', name: 'user_get', methods: ['GET'])]
    public function getProfile(string $id): JsonResponse
    {
        $user = $this->userRepo->findOneBy(['id' => Uuid::fromString($id)]);

        if (!$user) {
            return $this->json(['message' => 'Utilisateur introuvable.'], 404);
        }

        // Seul l'utilisateur lui-même peut voir son profil
        if ($user->getUserIdentifier() !== $this->getUser()->getUserIdentifier()) {
            return $this->json(['message' => 'Accès refusé.'], 403);
        }

        return $this->json($user->toArray());
    }

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
