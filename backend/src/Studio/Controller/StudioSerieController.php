<?php

namespace App\Studio\Controller;

use App\Entity\Episode;
use App\Entity\Season;
use App\Entity\Serie;
use App\Entity\User;
use App\Studio\Service\ContentLifecycleService;
use App\Studio\Service\StudioOwnershipChecker;
use App\Repository\EpisodeRepository;
use App\Repository\SeasonRepository;
use App\Repository\SerieRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Uid\Uuid;

#[Route('/api/studio/series')]
#[IsGranted('ROLE_CREATEUR')]
class StudioSerieController extends AbstractController
{
    public function __construct(
        private readonly SerieRepository $serieRepo,
        private readonly SeasonRepository $seasonRepo,
        private readonly EpisodeRepository $episodeRepo,
        private readonly StudioOwnershipChecker $ownershipChecker,
        private readonly ContentLifecycleService $lifecycle,
        private readonly EntityManagerInterface $em,
        private readonly SluggerInterface $slugger,
    ) {
    }

    // -----------------------------------------------------------------
    // Serie CRUD
    // -----------------------------------------------------------------

    #[Route('', name: 'studio_series_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $studio = $this->ownershipChecker->getStudioForUser($user);

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 30)));
        $status = $request->query->get('status');

        $allowed = [
            Serie::STATUS_DRAFT,
            Serie::STATUS_PUBLISHED,
            Serie::STATUS_WITHDRAWN,
            Serie::STATUS_PENDING_APPROVAL,
        ];
        if ($status !== null && $status !== '' && !\in_array($status, $allowed, true)) {
            return new JsonResponse(['message' => 'Statut invalide.'], 400);
        }

        $result = $this->serieRepo->findByStudioPaginated($studio, $status, $page, $limit);

        return new JsonResponse([
            'data' => array_map(fn(Serie $s) => $s->toArray(true), $result['data']),
            'total' => $result['total'],
            'page' => $result['page'],
            'limit' => $result['limit'],
        ]);
    }

    #[Route('/{id}', name: 'studio_series_get', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function get(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $serie = $this->resolveSerieForUser($id, $user);
        if ($serie instanceof JsonResponse) {
            return $serie;
        }

        // Vue détail : on inclut les épisodes dans chaque saison (`deepEpisodes=true`)
        // pour que la page d'édition studio puisse les lister. La liste paginée
        // (`list()`) reste shallow pour éviter un payload trop lourd.
        return new JsonResponse($serie->toArray(true, true));
    }

    #[Route('', name: 'studio_series_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $studio = $this->ownershipChecker->getStudioForUser($user);

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['message' => 'Corps JSON invalide.'], 400);
        }

        foreach (['title', 'synopsis', 'year'] as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                return new JsonResponse(['message' => sprintf('Le champ "%s" est requis.', $field)], 400);
            }
        }

        $title = (string) $data['title'];
        $slug = isset($data['slug']) && $data['slug'] !== ''
            ? (string) $data['slug']
            : $this->slugger->slug($title)->lower() . '-' . substr(bin2hex(random_bytes(4)), 0, 6);

        $serie = new Serie();
        $serie->setTitle($title);
        $serie->setSlug($slug);
        $serie->setSynopsis((string) $data['synopsis']);
        $serie->setYear((int) $data['year']);
        $serie->setStudio($studio);
        $serie->setStatus(Serie::STATUS_DRAFT);

        $this->em->persist($serie);
        $this->em->flush();

        return new JsonResponse($serie->toArray(true), 201);
    }

    #[Route('/{id}', name: 'studio_series_patch', methods: ['PATCH'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function patch(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $serie = $this->resolveSerieForUser($id, $user);
        if ($serie instanceof JsonResponse) {
            return $serie;
        }

        // Une série en attente d'approbation est gelée côté studio.
        if ($serie->getStatus() === Serie::STATUS_PENDING_APPROVAL) {
            return new JsonResponse(
                ['message' => "Contenu en attente d'approbation : modification impossible."],
                409,
            );
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['message' => 'Corps JSON invalide.'], 400);
        }

        if (isset($data['title'])) { $serie->setTitle((string) $data['title']); }
        if (isset($data['synopsis'])) { $serie->setSynopsis((string) $data['synopsis']); }
        if (isset($data['year'])) { $serie->setYear((int) $data['year']); }
        if (\array_key_exists('poster', $data)) { $serie->setPoster($data['poster'] === null ? null : (string) $data['poster']); }
        if (\array_key_exists('trailerVideoId', $data)) { $serie->setTrailerVideoId($data['trailerVideoId'] === null ? null : (string) $data['trailerVideoId']); }

        $this->em->flush();

        return new JsonResponse($serie->toArray(true));
    }

    #[Route('/{id}', name: 'studio_series_delete', methods: ['DELETE'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function delete(string $id): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $serie = $this->resolveSerieForUser($id, $user);
        if ($serie instanceof JsonResponse) {
            return $serie;
        }

        if ($serie->getStatus() !== Serie::STATUS_DRAFT) {
            return new JsonResponse([
                'message' => 'Une série publiée ne peut être supprimée directement, faites une demande de retrait.',
            ], 409);
        }

        $this->em->remove($serie);
        $this->em->flush();

        return new Response('', 204);
    }

    #[Route('/{id}/publish', name: 'studio_series_publish', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function publish(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $serie = $this->resolveSerieForUser($id, $user);
        if ($serie instanceof JsonResponse) {
            return $serie;
        }

        $this->lifecycle->publishSerie($serie);

        return new JsonResponse($serie->toArray(true));
    }

    #[Route('/{id}/withdraw', name: 'studio_series_withdraw', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function withdraw(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $serie = $this->resolveSerieForUser($id, $user);
        if ($serie instanceof JsonResponse) {
            return $serie;
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || !isset($data['reason']) || trim((string) $data['reason']) === '') {
            return new JsonResponse(['message' => 'Le champ "reason" est requis.'], 400);
        }

        $studio = $this->ownershipChecker->getStudioForUser($user);
        $withdrawal = $this->lifecycle->requestWithdrawal(
            'serie',
            $serie->getId(),
            $studio,
            $user,
            (string) $data['reason'],
        );

        return new JsonResponse($withdrawal->toArray(), 201);
    }

    // -----------------------------------------------------------------
    // Seasons
    // -----------------------------------------------------------------

    #[Route('/{serieId}/seasons', name: 'studio_seasons_create', methods: ['POST'], requirements: ['serieId' => '[0-9a-fA-F-]{36}'])]
    public function createSeason(string $serieId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $serie = $this->resolveSerieForUser($serieId, $user);
        if ($serie instanceof JsonResponse) {
            return $serie;
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || !isset($data['number'])) {
            return new JsonResponse(['message' => 'Le champ "number" est requis.'], 400);
        }

        $season = new Season();
        $season->setSerie($serie);
        $season->setNumber((int) $data['number']);
        if (isset($data['title'])) { $season->setTitle((string) $data['title']); }
        if (isset($data['synopsis'])) { $season->setSynopsis((string) $data['synopsis']); }

        $this->em->persist($season);
        $this->em->flush();

        return new JsonResponse($season->toArray(false), 201);
    }

    #[Route('/{serieId}/seasons/{seasonId}', name: 'studio_seasons_patch', methods: ['PATCH'], requirements: ['serieId' => '[0-9a-fA-F-]{36}', 'seasonId' => '[0-9a-fA-F-]{36}'])]
    public function patchSeason(string $serieId, string $seasonId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $resolved = $this->resolveSeasonForUser($serieId, $seasonId, $user);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [, $season] = $resolved;

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['message' => 'Corps JSON invalide.'], 400);
        }

        if (isset($data['number'])) { $season->setNumber((int) $data['number']); }
        if (\array_key_exists('title', $data)) { $season->setTitle($data['title'] === null ? null : (string) $data['title']); }
        if (\array_key_exists('synopsis', $data)) { $season->setSynopsis($data['synopsis'] === null ? null : (string) $data['synopsis']); }

        $this->em->flush();

        return new JsonResponse($season->toArray(false));
    }

    #[Route('/{serieId}/seasons/{seasonId}', name: 'studio_seasons_delete', methods: ['DELETE'], requirements: ['serieId' => '[0-9a-fA-F-]{36}', 'seasonId' => '[0-9a-fA-F-]{36}'])]
    public function deleteSeason(string $serieId, string $seasonId): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $resolved = $this->resolveSeasonForUser($serieId, $seasonId, $user);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [, $season] = $resolved;

        $this->em->remove($season);
        $this->em->flush();

        return new Response('', 204);
    }

    // -----------------------------------------------------------------
    // Episodes
    // -----------------------------------------------------------------

    #[Route('/{serieId}/seasons/{seasonId}/episodes', name: 'studio_episodes_create', methods: ['POST'], requirements: ['serieId' => '[0-9a-fA-F-]{36}', 'seasonId' => '[0-9a-fA-F-]{36}'])]
    public function createEpisode(string $serieId, string $seasonId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $resolved = $this->resolveSeasonForUser($serieId, $seasonId, $user);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [, $season] = $resolved;

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['message' => 'Corps JSON invalide.'], 400);
        }
        foreach (['number', 'title'] as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                return new JsonResponse(['message' => sprintf('Le champ "%s" est requis.', $field)], 400);
            }
        }

        $number = (int) $data['number'];
        if ($number < 1) {
            return new JsonResponse(['message' => 'Le numéro d\'épisode doit être >= 1.'], 400);
        }

        // Pré-check unicité (season_id, number) — sinon Doctrine lève une
        // UniqueConstraintViolationException qui remonte en 500 brut.
        $existing = $this->episodeRepo->findOneBy(['season' => $season, 'number' => $number]);
        if ($existing !== null) {
            return new JsonResponse(
                ['message' => sprintf('Un épisode portant le numéro %d existe déjà dans cette saison.', $number)],
                409,
            );
        }

        // Phase H — Si bunnyVideoId fourni à la création, doit appartenir
        // au studio (path `studios/{slug}/...`).
        if (isset($data['bunnyVideoId']) && $data['bunnyVideoId'] !== '' && $data['bunnyVideoId'] !== null) {
            $studio = $this->ownershipChecker->getStudioForUser($user);
            $this->ownershipChecker->assertBunnyPathOwnership((string) $data['bunnyVideoId'], $studio);
        }

        $episode = new Episode();
        $episode->setSeason($season);
        $episode->setNumber($number);
        $episode->setTitle((string) $data['title']);
        if (isset($data['synopsis'])) { $episode->setSynopsis((string) $data['synopsis']); }
        if (isset($data['duration'])) { $episode->setDuration((int) $data['duration']); }
        if (isset($data['bunnyVideoId'])) { $episode->setBunnyVideoId((string) $data['bunnyVideoId']); }

        $this->em->persist($episode);
        $this->em->flush();

        return new JsonResponse($episode->toArray(), 201);
    }

    #[Route('/{serieId}/seasons/{seasonId}/episodes/{episodeId}', name: 'studio_episodes_patch', methods: ['PATCH'], requirements: ['serieId' => '[0-9a-fA-F-]{36}', 'seasonId' => '[0-9a-fA-F-]{36}', 'episodeId' => '[0-9a-fA-F-]{36}'])]
    public function patchEpisode(string $serieId, string $seasonId, string $episodeId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $resolved = $this->resolveEpisodeForUser($serieId, $seasonId, $episodeId, $user);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        $episode = $resolved;

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['message' => 'Corps JSON invalide.'], 400);
        }

        if (isset($data['number'])) {
            $newNumber = (int) $data['number'];
            if ($newNumber < 1) {
                return new JsonResponse(['message' => 'Le numéro d\'épisode doit être >= 1.'], 400);
            }
            if ($newNumber !== $episode->getNumber()) {
                // Pré-check unicité (season_id, number) en cas de changement de numéro
                $conflict = $this->episodeRepo->findOneBy([
                    'season' => $episode->getSeason(),
                    'number' => $newNumber,
                ]);
                if ($conflict !== null && $conflict->getId()->toRfc4122() !== $episode->getId()->toRfc4122()) {
                    return new JsonResponse(
                        ['message' => sprintf('Un épisode portant le numéro %d existe déjà dans cette saison.', $newNumber)],
                        409,
                    );
                }
            }
            $episode->setNumber($newNumber);
        }
        if (isset($data['title'])) { $episode->setTitle((string) $data['title']); }
        if (\array_key_exists('synopsis', $data)) { $episode->setSynopsis($data['synopsis'] === null ? null : (string) $data['synopsis']); }
        if (isset($data['duration'])) { $episode->setDuration((int) $data['duration']); }

        // Phase H — modification du bunnyVideoId d'un épisode :
        // - refuse si la valeur courante est un path "importé" (non `studios/...`)
        // - exige que la nouvelle valeur appartienne au studio
        if (\array_key_exists('bunnyVideoId', $data)) {
            $newValue = $data['bunnyVideoId'];
            $current = $episode->getBunnyVideoId();
            if ($this->ownershipChecker->isImportedBunnyPath($current)) {
                return new JsonResponse([
                    'message' => "Le chemin Bunny d'un contenu importé ne peut être modifié.",
                ], 403);
            }
            if ($newValue !== null && $newValue !== '') {
                $studio = $this->ownershipChecker->getStudioForUser($user);
                $this->ownershipChecker->assertBunnyPathOwnership((string) $newValue, $studio);
            }
            $episode->setBunnyVideoId($newValue === null ? null : (string) $newValue);
        }

        $this->em->flush();

        return new JsonResponse($episode->toArray());
    }

    #[Route('/{serieId}/seasons/{seasonId}/episodes/{episodeId}', name: 'studio_episodes_delete', methods: ['DELETE'], requirements: ['serieId' => '[0-9a-fA-F-]{36}', 'seasonId' => '[0-9a-fA-F-]{36}', 'episodeId' => '[0-9a-fA-F-]{36}'])]
    public function deleteEpisode(string $serieId, string $seasonId, string $episodeId): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $resolved = $this->resolveEpisodeForUser($serieId, $seasonId, $episodeId, $user);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        $this->em->remove($resolved);
        $this->em->flush();

        return new Response('', 204);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function resolveSerieForUser(string $id, User $user): Serie|JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['message' => 'Identifiant invalide.'], 400);
        }

        $serie = $this->serieRepo->find($uuid);
        if ($serie === null) {
            return new JsonResponse(['message' => 'Série introuvable.'], 404);
        }

        $this->ownershipChecker->assertOwnsSerie($serie, $user);

        return $serie;
    }

    /**
     * @return array{0: Serie, 1: Season}|JsonResponse
     */
    private function resolveSeasonForUser(string $serieId, string $seasonId, User $user): array|JsonResponse
    {
        $serie = $this->resolveSerieForUser($serieId, $user);
        if ($serie instanceof JsonResponse) {
            return $serie;
        }

        try {
            $sUuid = Uuid::fromString($seasonId);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['message' => 'Identifiant de saison invalide.'], 400);
        }

        $season = $this->seasonRepo->find($sUuid);
        if ($season === null || $season->getSerie()->getId()->toRfc4122() !== $serie->getId()->toRfc4122()) {
            return new JsonResponse(['message' => 'Saison introuvable.'], 404);
        }

        return [$serie, $season];
    }

    private function resolveEpisodeForUser(string $serieId, string $seasonId, string $episodeId, User $user): Episode|JsonResponse
    {
        $resolved = $this->resolveSeasonForUser($serieId, $seasonId, $user);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [, $season] = $resolved;

        try {
            $eUuid = Uuid::fromString($episodeId);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['message' => 'Identifiant d\'épisode invalide.'], 400);
        }

        $episode = $this->episodeRepo->find($eUuid);
        if ($episode === null || $episode->getSeason()->getId()->toRfc4122() !== $season->getId()->toRfc4122()) {
            return new JsonResponse(['message' => 'Épisode introuvable.'], 404);
        }

        return $episode;
    }
}
