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


/**
 * Profil du studio de l'utilisateur connecté (préfixe `/api/studio`) : c'est
 * la source du tableau de bord de l'espace studio.
 *
 * Accès : ROLE_CREATEUR (attribut `#[IsGranted]` + règle `access_control`
 * `^/api/studio`). Le studio est toujours celui de l'utilisateur connecté,
 * résolu par StudioOwnershipChecker::getStudioForUser() (403 s'il n'en a pas
 * ou si son studio est inactif).
 *
 * Endpoints :
 *  - GET   /me  studio courant + compteurs du tableau de bord
 *  - PATCH /me  modification du nom et/ou de la description du studio
 */
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
     *
     * Les totaux (`totalFilms`, `totalSeries`) comptent tous les statuts, y compris
     * PENDING_APPROVAL qui n'a pas de compteur dédié.
     *
     * @return JsonResponse 200 `{studio: Studio::toArray(), stats: {totalFilms, publishedFilms,
     *                      draftFilms, withdrawnFilms, totalSeries, publishedSeries, draftSeries,
     *                      withdrawnSeries, pendingWithdrawals, subscribersCount}}` ;
     *                      403 si l'utilisateur n'a pas de studio actif
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

    /**
     * Met à jour le nom et/ou la description du studio de l'utilisateur.
     *
     * Au moins l'un des deux champs doit être présent. Une valeur fournie est
     * nettoyée (trim) puis doit être non vide : 255 caractères au plus pour
     * `name`, 2 000 pour `description`. Le slug et le dossier Bunny du studio
     * ne sont pas modifiés : les chemins `studios/{slug}/...` déjà enregistrés
     * restent valides après un renommage.
     *
     * NB : les erreurs de cet endpoint sont renvoyées sous la clé `error`
     * (et non `message` comme dans les autres contrôleurs studio).
     *
     * @return JsonResponse 200 studio mis à jour (`Studio::toArray()`) ; 400 si le JSON est
     *                      invalide, si aucun champ n'est fourni ou si une valeur est vide
     *                      ou trop longue ; 403 si l'utilisateur n'a pas de studio actif ;
     *                      409 si le nom est déjà pris par un autre studio
     */
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

        // Pas de pré-contrôle du nom : c'est l'index unique `studio.name` qui
        // détecte le doublon au flush, traduit ici en 409.
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            return new JsonResponse(['error' => 'Ce nom de studio est déjà utilisé.'], 409);
        }

        return new JsonResponse($studio->toArray(), 200);
    }
}
