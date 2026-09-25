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

/**
 * Gestion des séries d'un studio, de leurs saisons et de leurs épisodes depuis
 * l'espace studio (préfixe `/api/studio/series`).
 *
 * Accès : ROLE_CREATEUR (attribut `#[IsGranted]` + règle `access_control`
 * `^/api/studio`). Chaque action résout d'abord la série et vérifie qu'elle
 * appartient au studio actif de l'utilisateur (StudioOwnershipChecker, 403
 * sinon) ; saisons et épisodes héritent de ce contrôle par leur série. Les
 * identifiants de route doivent avoir la forme d'un UUID (36 caractères
 * hexadécimaux ou tirets), sinon aucune route ne correspond (404).
 *
 * Endpoints :
 *  - GET    ''                                  liste paginée des séries du studio
 *  - GET    /{id}                               détail, saisons et épisodes inclus
 *  - POST   ''                                  création d'une série, toujours en DRAFT
 *  - PATCH  /{id}                               modification partielle (refusée en PENDING_APPROVAL)
 *  - DELETE /{id}                               suppression, possible uniquement en DRAFT
 *  - POST   /{id}/publish                       publication (PUBLISHED, ou PENDING_APPROVAL)
 *  - POST   /{id}/withdraw                      demande de retrait adressée à l'admin
 *  - POST   /{serieId}/seasons                  création d'une saison
 *  - PATCH  /{serieId}/seasons/{seasonId}       modification d'une saison
 *  - DELETE /{serieId}/seasons/{seasonId}       suppression d'une saison et de ses épisodes
 *  - POST   /{serieId}/seasons/{seasonId}/episodes               création d'un épisode
 *  - PATCH  /{serieId}/seasons/{seasonId}/episodes/{episodeId}   modification d'un épisode
 *  - DELETE /{serieId}/seasons/{seasonId}/episodes/{episodeId}   suppression d'un épisode
 *
 * Le gel en PENDING_APPROVAL et la règle « suppression en DRAFT uniquement »
 * ne portent que sur la série elle-même : les routes des saisons et des
 * épisodes ne consultent pas le statut de la série.
 */
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

    /**
     * Liste paginée des séries du studio de l'utilisateur, de la plus récente à la plus ancienne.
     *
     * Paramètres de requête : `page` (défaut 1), `limit` (défaut 30, borné entre 1 et 100)
     * et `status` optionnel (DRAFT, PUBLISHED, WITHDRAWN ou PENDING_APPROVAL).
     *
     * @return JsonResponse 200 `{data, total, page, limit}`, chaque série au format
     *                      `Serie::toArray(true)` (saisons incluses, sans leurs épisodes) ;
     *                      400 si `status` est inconnu ; 403 si pas de studio actif
     */
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

    /**
     * Détail d'une série du studio, avec ses saisons et leurs épisodes.
     *
     * @return JsonResponse 200 série (`Serie::toArray(true, true)`) ; 400 si `id` n'est pas
     *                      un UUID valide ; 403 si la série appartient à un autre studio ;
     *                      404 si elle n'existe pas
     */
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

    /**
     * Crée une série en DRAFT, rattachée au studio de l'utilisateur.
     *
     * Corps JSON : `title`, `synopsis` et `year` obligatoires ; `slug` facultatif
     * (sinon dérivé du titre avec un suffixe aléatoire de 6 caractères hexadécimaux,
     * comme pour les films). Saisons et épisodes s'ajoutent ensuite par leurs routes dédiées.
     *
     * @return JsonResponse 201 série créée (`Serie::toArray(true)`) ; 400 si le JSON est
     *                      invalide ou si un champ obligatoire manque ; 403 si pas de studio actif
     */
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

    /**
     * Modifie partiellement une série du studio : seuls les champs présents dans
     * le corps JSON sont appliqués.
     *
     * Champs acceptés : `title`, `synopsis`, `year` (une valeur null y est ignorée) ;
     * `poster` et `trailerVideoId` (null efface la valeur).
     *
     * @return JsonResponse 200 série modifiée ; 400 si `id` n'est pas un UUID valide ou si le
     *                      JSON est invalide ; 403 si la série appartient à un autre studio ;
     *                      404 si elle n'existe pas ; 409 si elle est en PENDING_APPROVAL
     */
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

    /**
     * Supprime définitivement une série encore en DRAFT, avec ses saisons et
     * épisodes (cascade `remove` déclarée sur Serie::$seasons et Season::$episodes).
     * Les fichiers déjà envoyés sur Bunny ne sont pas supprimés.
     *
     * Dans tout autre statut, la réponse est 409 : le retrait d'une série publiée
     * passe par une demande de retrait validée par un administrateur.
     *
     * @return Response 204 sans contenu ; 400 si `id` n'est pas un UUID valide ; 403 si la
     *                  série appartient à un autre studio ; 404 si elle n'existe pas ;
     *                  409 si elle n'est pas en DRAFT
     */
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

    /**
     * Publie une série en DRAFT (transition déléguée à ContentLifecycleService::publishSerie()).
     *
     * La série passe en PUBLISHED si le studio est validé, sinon en PENDING_APPROVAL
     * dans l'attente d'une approbation administrateur.
     *
     * @return JsonResponse 200 série avec son nouveau statut ; 400 si elle n'est pas en DRAFT
     *                      ou si `id` n'est pas un UUID valide ; 403 si elle appartient à
     *                      un autre studio ; 404 si elle n'existe pas
     */
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

    /**
     * Demande le retrait d'une série : crée une WithdrawalRequest en PENDING que
     * l'admin approuvera (série → WITHDRAWN) ou rejettera.
     *
     * Corps JSON : `reason` obligatoire (non vide). Comme pour les films, le statut
     * de la série n'est pas contrôlé ici ; seule l'unicité d'une demande PENDING
     * par contenu est imposée.
     *
     * @return JsonResponse 201 demande créée (`WithdrawalRequest::toArray()`) ; 400 si `reason`
     *                      manque ou si `id` n'est pas un UUID valide ; 403 si la série
     *                      appartient à un autre studio ; 404 si elle n'existe pas ;
     *                      409 si une demande de retrait est déjà en attente
     */
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

    /**
     * Ajoute une saison à une série du studio.
     *
     * Corps JSON : `number` obligatoire ; `title` et `synopsis` facultatifs.
     * NB : contrairement aux épisodes, le numéro n'est pas pré-vérifié ; un doublon
     * (même série, même numéro) viole l'index unique `uniq_season_serie_number`
     * au flush et remonte en erreur 500.
     *
     * @return JsonResponse 201 saison créée (`Season::toArray(false)` : id, number, title,
     *                      synopsis) ; 400 si `number` manque ou si `serieId` n'est pas un
     *                      UUID valide ; 403 si la série appartient à un autre studio ;
     *                      404 si elle n'existe pas
     */
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

    /**
     * Modifie partiellement une saison d'une série du studio.
     *
     * Champs acceptés : `number` (null ignoré ; pas de pré-contrôle d'unicité, un
     * numéro déjà pris fait échouer le flush) ; `title` et `synopsis` (null efface
     * la valeur).
     *
     * @return JsonResponse 200 saison modifiée (`Season::toArray(false)`) ; 400 si un
     *                      identifiant n'est pas un UUID valide ou si le JSON est invalide ;
     *                      403 si la série appartient à un autre studio ; 404 si la série
     *                      ou la saison est introuvable (ou si la saison est d'une autre série)
     */
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

    /**
     * Supprime une saison et, par cascade Doctrine, tous ses épisodes.
     *
     * @return Response 204 sans contenu ; 400 si un identifiant n'est pas un UUID valide ;
     *                  403 si la série appartient à un autre studio ; 404 si la série ou
     *                  la saison est introuvable
     */
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

    /**
     * Ajoute un épisode à une saison d'une série du studio.
     *
     * Corps JSON : `number` (entier >= 1, unique dans la saison) et `title` obligatoires ;
     * `synopsis`, `duration` (en minutes) et `bunnyVideoId` facultatifs. La vidéo peut
     * aussi être envoyée après coup via `POST /api/studio/upload` (targetType=episode),
     * qui range le fichier sous `studios/{studio}/{serie}/saison-{N}/episode-{NN}/`.
     *
     * @return JsonResponse 201 épisode créé (`Episode::toArray()`) ; 400 si le JSON est
     *                      invalide, si un champ obligatoire manque, si `number` < 1 ou si
     *                      un identifiant n'est pas un UUID valide ; 403 si la série appartient
     *                      à un autre studio ou si `bunnyVideoId` sort de `studios/{slug-du-studio}/` ;
     *                      404 si la série ou la saison est introuvable ; 409 si le numéro
     *                      est déjà pris dans la saison
     */
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

    /**
     * Modifie partiellement un épisode.
     *
     * Champs acceptés : `number` (>= 1, unique dans la saison), `title` et `duration`
     * (null ignoré) ; `synopsis` (null efface la valeur) ; `bunnyVideoId`, soumis aux
     * mêmes règles de propriété du chemin Bunny que pour les films (Phase H).
     *
     * @return JsonResponse 200 épisode modifié (`Episode::toArray()`) ; 400 si le JSON est
     *                      invalide, si `number` < 1 ou si un identifiant n'est pas un UUID
     *                      valide ; 403 si la série appartient à un autre studio, si le chemin
     *                      Bunny actuel provient de l'import ou si le nouveau sort de
     *                      `studios/{slug-du-studio}/` ; 404 si la série, la saison ou
     *                      l'épisode est introuvable ; 409 si le nouveau numéro est déjà pris
     */
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

    /**
     * Supprime un épisode (le fichier vidéo éventuel n'est pas supprimé de Bunny).
     *
     * @return Response 204 sans contenu ; 400 si un identifiant n'est pas un UUID valide ;
     *                  403 si la série appartient à un autre studio ; 404 si la série,
     *                  la saison ou l'épisode est introuvable
     */
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

    /**
     * Résout une série par UUID et vérifie qu'elle appartient au studio actif de l'utilisateur.
     *
     * @return Serie|JsonResponse la série, ou une réponse d'erreur 400 (UUID invalide)
     *                            ou 404 (série introuvable)
     *
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException (403) si
     *         la série n'appartient pas au studio actif de l'utilisateur
     */
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
     * Résout une saison après avoir contrôlé sa série (resolveSerieForUser()).
     *
     * Une saison qui existe mais appartient à une autre série est traitée comme
     * introuvable (404) : on ne peut pas atteindre la saison d'un autre studio
     * en la combinant avec l'identifiant d'une de ses propres séries.
     *
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

    /**
     * Résout un épisode en remontant la chaîne série → saison → épisode, chaque
     * maillon devant appartenir au précédent (sinon 404).
     *
     * @return Episode|JsonResponse l'épisode, ou la réponse d'erreur 400/404 de l'un
     *                              des niveaux (le 403 de propriété est levé par
     *                              resolveSerieForUser())
     */
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
