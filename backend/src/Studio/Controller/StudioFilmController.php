<?php

namespace App\Studio\Controller;

use App\Entity\Film;
use App\Entity\User;
use App\Studio\Service\ContentLifecycleService;
use App\Studio\Service\StudioOwnershipChecker;
use App\Repository\FilmRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Uid\Uuid;

#[Route('/api/studio/films')]
#[IsGranted('ROLE_CREATEUR')]
class StudioFilmController extends AbstractController
{
    public function __construct(
        private readonly FilmRepository $filmRepo,
        private readonly StudioOwnershipChecker $ownershipChecker,
        private readonly ContentLifecycleService $lifecycle,
        private readonly EntityManagerInterface $em,
        private readonly SluggerInterface $slugger,
    ) {
    }

    #[Route('', name: 'studio_films_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $studio = $this->ownershipChecker->getStudioForUser($user);

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 30)));
        $status = $request->query->get('status');

        $allowedStatuses = [
            Film::STATUS_DRAFT,
            Film::STATUS_PUBLISHED,
            Film::STATUS_WITHDRAWN,
            Film::STATUS_PENDING_APPROVAL,
        ];
        if ($status !== null && $status !== '' && !\in_array($status, $allowedStatuses, true)) {
            return new JsonResponse(['message' => 'Statut invalide.'], 400);
        }

        $result = $this->filmRepo->findByStudioPaginated($studio, $status, $page, $limit);

        return new JsonResponse([
            'data' => array_map(fn(Film $f) => $f->toArray(true), $result['data']),
            'total' => $result['total'],
            'page' => $result['page'],
            'limit' => $result['limit'],
        ]);
    }

    #[Route('/{id}', name: 'studio_films_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $film = $this->resolveFilmForUser($id, $user);
        if ($film instanceof JsonResponse) {
            return $film;
        }

        return new JsonResponse($film->toArray(true));
    }

    #[Route('', name: 'studio_films_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $studio = $this->ownershipChecker->getStudioForUser($user);

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['message' => 'Corps JSON invalide.'], 400);
        }

        foreach (['title', 'synopsis', 'year', 'duration'] as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                return new JsonResponse(['message' => sprintf('Le champ "%s" est requis.', $field)], 400);
            }
        }

        // Phase H — si un bunnyVideoId est fourni à la création, il doit
        // appartenir au studio (path `studios/{slug}/...`). Un producteur
        // ne peut pas créer un contenu pointant vers le path d'un autre
        // studio.
        if (isset($data['bunnyVideoId']) && $data['bunnyVideoId'] !== '' && $data['bunnyVideoId'] !== null) {
            $this->ownershipChecker->assertBunnyPathOwnership((string) $data['bunnyVideoId'], $studio);
        }

        $title = (string) $data['title'];
        $slug = isset($data['slug']) && $data['slug'] !== ''
            ? (string) $data['slug']
            : $this->slugger->slug($title)->lower() . '-' . substr(bin2hex(random_bytes(4)), 0, 6);

        $film = new Film();
        $film->setTitle($title);
        $film->setSlug($slug);
        $film->setSynopsis((string) $data['synopsis']);
        $film->setYear((int) $data['year']);
        $film->setDuration((int) $data['duration']);
        if (isset($data['bunnyVideoId']) && $data['bunnyVideoId'] !== '' && $data['bunnyVideoId'] !== null) {
            $film->setBunnyVideoId((string) $data['bunnyVideoId']);
        }
        $film->setStudio($studio);
        $film->setStatus(Film::STATUS_DRAFT);

        $this->em->persist($film);
        $this->em->flush();

        return new JsonResponse($film->toArray(true), 201);
    }

    #[Route('/{id}', name: 'studio_films_patch', methods: ['PATCH'])]
    public function patch(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $film = $this->resolveFilmForUser($id, $user);
        if ($film instanceof JsonResponse) {
            return $film;
        }

        // Un contenu en attente d'approbation est gelé côté studio :
        // l'admin doit d'abord refuser pour repasser en DRAFT.
        if ($film->getStatus() === Film::STATUS_PENDING_APPROVAL) {
            return new JsonResponse(
                ['message' => "Contenu en attente d'approbation : modification impossible."],
                409,
            );
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['message' => 'Corps JSON invalide.'], 400);
        }

        if (isset($data['title'])) { $film->setTitle((string) $data['title']); }
        if (isset($data['synopsis'])) { $film->setSynopsis((string) $data['synopsis']); }
        if (isset($data['year'])) { $film->setYear((int) $data['year']); }
        if (isset($data['duration'])) { $film->setDuration((int) $data['duration']); }
        if (\array_key_exists('poster', $data)) { $film->setPoster($data['poster'] === null ? null : (string) $data['poster']); }
        if (\array_key_exists('trailerVideoId', $data)) { $film->setTrailerVideoId($data['trailerVideoId'] === null ? null : (string) $data['trailerVideoId']); }

        // Phase H — restriction sur la modification de bunnyVideoId :
        // 1) Si l'entité a déjà un path "importé" (ne commence pas par
        //    `studios/`, ex: `12_CAS/CAS_1/CAS1_E01` issu de Phase F), le
        //    producteur ne peut pas le modifier (l'admin peut via
        //    /api/admin/films/{id} qui n'a pas cette contrainte).
        // 2) Sinon, le nouveau path doit pointer vers `studios/{slug}/...`.
        if (\array_key_exists('bunnyVideoId', $data)) {
            $newValue = $data['bunnyVideoId'];
            $current = $film->getBunnyVideoId();
            if ($this->ownershipChecker->isImportedBunnyPath($current)) {
                return new JsonResponse([
                    'message' => "Le chemin Bunny d'un contenu importé ne peut être modifié.",
                ], 403);
            }
            if ($newValue !== null && $newValue !== '') {
                $studio = $this->ownershipChecker->getStudioForUser($user);
                $this->ownershipChecker->assertBunnyPathOwnership((string) $newValue, $studio);
            }
            $film->setBunnyVideoId($newValue === null ? null : (string) $newValue);
        }

        $this->em->flush();

        return new JsonResponse($film->toArray(true));
    }

    #[Route('/{id}', name: 'studio_films_delete', methods: ['DELETE'])]
    public function delete(string $id): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $film = $this->resolveFilmForUser($id, $user);
        if ($film instanceof JsonResponse) {
            return $film;
        }

        if ($film->getStatus() !== Film::STATUS_DRAFT) {
            return new JsonResponse([
                'message' => 'Un film publié ne peut être supprimé directement, faites une demande de retrait.',
            ], 409);
        }

        $this->em->remove($film);
        $this->em->flush();

        return new Response('', 204);
    }

    #[Route('/{id}/publish', name: 'studio_films_publish', methods: ['POST'])]
    public function publish(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $film = $this->resolveFilmForUser($id, $user);
        if ($film instanceof JsonResponse) {
            return $film;
        }

        $this->lifecycle->publishFilm($film);

        return new JsonResponse($film->toArray(true));
    }

    #[Route('/{id}/withdraw', name: 'studio_films_withdraw', methods: ['POST'])]
    public function withdraw(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $film = $this->resolveFilmForUser($id, $user);
        if ($film instanceof JsonResponse) {
            return $film;
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || !isset($data['reason']) || trim((string) $data['reason']) === '') {
            return new JsonResponse(['message' => 'Le champ "reason" est requis.'], 400);
        }

        $studio = $this->ownershipChecker->getStudioForUser($user);
        $withdrawal = $this->lifecycle->requestWithdrawal(
            'film',
            $film->getId(),
            $studio,
            $user,
            (string) $data['reason'],
        );

        return new JsonResponse($withdrawal->toArray(), 201);
    }

    /**
     * Résout un film par UUID et vérifie l'ownership. Retourne soit Film,
     * soit une JsonResponse d'erreur (400 invalid uuid, 404 not found, 403 not owner).
     */
    private function resolveFilmForUser(string $id, User $user): Film|JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['message' => 'Identifiant invalide.'], 400);
        }

        $film = $this->filmRepo->find($uuid);
        if ($film === null) {
            return new JsonResponse(['message' => 'Film introuvable.'], 404);
        }

        $this->ownershipChecker->assertOwnsFilm($film, $user);

        return $film;
    }
}
