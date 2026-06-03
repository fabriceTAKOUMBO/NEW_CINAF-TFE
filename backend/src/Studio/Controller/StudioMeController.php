<?php

namespace App\Studio\Controller;

use App\Entity\Film;
use App\Entity\Serie;
use App\Entity\User;
use App\Entity\WithdrawalRequest;
use App\Studio\Service\StudioOwnershipChecker;
use App\Repository\FilmRepository;
use App\Repository\SerieRepository;
use App\Repository\StudioSubscriptionRepository;
use App\Repository\WithdrawalRequestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\Request;


#[Route('/api/studio')]
#[IsGranted('ROLE_CREATEUR')]
class StudioMeController extends AbstractController
{
    public function __construct(
        private readonly StudioOwnershipChecker $ownershipChecker,
        private readonly FilmRepository $filmRepo,
        private readonly SerieRepository $serieRepo,
        private readonly WithdrawalRequestRepository $withdrawalRepo,
        private readonly StudioSubscriptionRepository $subscriptionRepo,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Studio courant + statistiques agrégées (compteurs par statut).
     */
    #[Route('/me', name: 'studio_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $studio = $this->ownershipChecker->getStudioForUser($user);

        $stats = [
            'totalFilms' => $this->filmRepo->countByStudio($studio),
            'publishedFilms' => $this->filmRepo->countByStudioAndStatus($studio, Film::STATUS_PUBLISHED),
            'draftFilms' => $this->filmRepo->countByStudioAndStatus($studio, Film::STATUS_DRAFT),
            'withdrawnFilms' => $this->filmRepo->countByStudioAndStatus($studio, Film::STATUS_WITHDRAWN),
            'totalSeries' => $this->serieRepo->countByStudio($studio),
            'publishedSeries' => $this->serieRepo->countByStudioAndStatus($studio, Serie::STATUS_PUBLISHED),
            'draftSeries' => $this->serieRepo->countByStudioAndStatus($studio, Serie::STATUS_DRAFT),
            'withdrawnSeries' => $this->serieRepo->countByStudioAndStatus($studio, Serie::STATUS_WITHDRAWN),
            'pendingWithdrawals' => $this->withdrawalRepo->countByStudioAndStatus(
                $studio,
                WithdrawalRequest::STATUS_PENDING
            ),
            // Compteur d'abonnés gratuits (« follow YouTube ») à la chaîne du
            // studio — affiché sur le dashboard producteur à côté des KPI
            // catalogue.
            'subscribersCount' => $this->subscriptionRepo->countByStudio($studio),
        ];

        return new JsonResponse([
            'studio' => $studio->toArray(),
            'stats' => $stats,
        ]);
    }

    #[Route('/me', name: 'studio_me_update', methods: ['PATCH'])]
    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $studio = $this->ownershipChecker->getStudioForUser($user);

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Corps JSON invalide.'], 400);
        }

        $hasName = array_key_exists('name', $payload);
        $hasDescription = array_key_exists('description', $payload);

        if (!$hasName && !$hasDescription) {
            return new JsonResponse(
                ['error' => 'Au moins un champ « name » ou « description » est requis.'],
                400
            );
        }

        if ($hasName) {
            $name = is_string($payload['name']) ? trim($payload['name']) : '';
            if ($name === '') {
                return new JsonResponse(['error' => 'Le nom du studio est obligatoire.'], 400);
            }
            if (mb_strlen($name) > 255) {
                return new JsonResponse(['error' => 'Le nom dépasse 255 caractères.'], 400);
            }
            $studio->setName($name);
        }

        if ($hasDescription) {
            $description = is_string($payload['description']) ? trim($payload['description']) : '';
            if ($description === '') {
                return new JsonResponse(['error' => 'La description du studio est obligatoire.'], 400);
            }
            if (mb_strlen($description) > 2000) {
                return new JsonResponse(['error' => 'La description dépasse 2 000 caractères.'], 400);
            }
            $studio->setDescription($description);
        }

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            return new JsonResponse(['error' => 'Ce nom de studio est déjà utilisé.'], 409);
        }

        return new JsonResponse($studio->toArray(), 200);
    }
}
