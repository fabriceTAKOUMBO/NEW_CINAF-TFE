<?php

namespace App\Admin\Controller;

use App\Entity\Film;
use App\Repository\FilmRepository;
use App\Repository\StudioRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Endpoints admin pour la gestion globale des films, tous studios confondus.
 * Calque le pattern de AdminUserController (resolveFilm, validation UUID, 400/404).
 *
 * Préfixe `/api/admin/films`, ROLE_ADMIN (`#[IsGranted]` + `access_control`).
 *  - GET    /       liste paginée filtrable (statut, studio, titre)
 *  - GET    /{id}   fiche détaillée
 *  - PATCH  /{id}   modération / correction (métadonnées, statut, studio, chemins Bunny)
 *  - DELETE /{id}   suppression définitive
 * Contrairement à l'espace studio, l'admin n'est soumis à aucun contrôle de
 * propriété (ni studio, ni préfixe Bunny `studios/{slug}/`).
 * L'approbation des films PENDING_APPROVAL passe par AdminApprovalController.
 */
#[Route('/api/admin/films')]
#[IsGranted('ROLE_ADMIN')]
class AdminFilmController extends AbstractController
{
    /**
     * Statuts acceptés en filtre de liste et en PATCH. PENDING_APPROVAL en est
     * exclu : il ne s'obtient que par la publication d'un studio non validé.
     */
    private const ALLOWED_STATUSES = [
        Film::STATUS_DRAFT,
        Film::STATUS_PUBLISHED,
        Film::STATUS_WITHDRAWN,
    ];

    public function __construct(
        private readonly FilmRepository $filmRepo,
        private readonly StudioRepository $studioRepo,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Liste paginée de tous les films, du plus récemment créé au plus ancien.
     *
     * Paramètres de requête : `page` (défaut 1), `limit` (défaut 20, borné à 1..100),
     * `status` (DRAFT, PUBLISHED ou WITHDRAWN), `studioId` (UUID), `search`
     * (sous-chaîne du titre, insensible à la casse).
     *
     * @return JsonResponse 200 `{data, total, page, limit}` (chaque film avec un résumé
     *                      de son studio) ; 400 statut ou studioId invalide
     */
    #[Route('', name: 'admin_films_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));
        $status = $request->query->get('status');
        $studioIdRaw = $request->query->get('studioId');
        $search = $request->query->get('search');

        if ($status !== null && $status !== '' && !\in_array($status, self::ALLOWED_STATUSES, true)) {
            return $this->json(['message' => 'Statut invalide.'], 400);
        }

        $studioUuid = null;
        if ($studioIdRaw !== null && $studioIdRaw !== '') {
            try {
                $studioUuid = Uuid::fromString($studioIdRaw);
            } catch (\InvalidArgumentException) {
                return $this->json(['message' => 'studioId invalide.'], 400);
            }
        }

        $items = $this->filmRepo->findAllPaginated($status, $studioUuid, $search, $page, $limit);
        $total = $this->filmRepo->countAll($status, $studioUuid, $search);

        return $this->json([
            'data' => array_map(fn (Film $f) => $this->serializeWithStudio($f), $items),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * Renvoie la fiche détaillée d'un film (relations complètes + résumé du studio).
     *
     * @return JsonResponse 200 ; 400 UUID mal formé ; 404 film introuvable
     */
    #[Route('/{id}', name: 'admin_films_get', methods: ['GET'])]
    public function getOne(string $id): JsonResponse
    {
        $film = $this->resolveFilm($id);
        if ($film instanceof JsonResponse) {
            return $film;
        }

        return $this->json($this->serializeWithStudio($film, true));
    }

    /**
     * Modifie un film (mise à jour partielle : seuls les champs présents sont traités).
     *
     * Champs acceptés : `title` (non vide), `synopsis`, `year`, `duration`,
     * `poster`, `trailerVideoId`, `bunnyVideoId` (null accepté pour ces trois
     * derniers), `status` (DRAFT, PUBLISHED ou WITHDRAWN) et `studioId`
     * (UUID, ou null pour détacher le film de son studio).
     * Le changement de statut est appliqué directement, sans passer par
     * ContentLifecycleService : un passage en PUBLISHED ne valide donc pas le studio.
     * Les modifications ne sont enregistrées (flush) que si tout le corps est valide.
     *
     * @return JsonResponse 200 fiche détaillée à jour ; 400 JSON, titre, statut ou studioId
     *                      invalide ; 404 film ou studio introuvable
     */
    #[Route('/{id}', name: 'admin_films_update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $film = $this->resolveFilm($id);
        if ($film instanceof JsonResponse) {
            return $film;
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['message' => 'Corps JSON invalide.'], 400);
        }

        if (\array_key_exists('title', $data)) {
            $title = trim((string) $data['title']);
            if ($title === '') {
                return $this->json(['message' => 'Le titre ne peut pas être vide.'], 400);
            }
            $film->setTitle($title);
        }

        if (\array_key_exists('synopsis', $data)) {
            $film->setSynopsis((string) $data['synopsis']);
        }

        if (\array_key_exists('year', $data)) {
            $film->setYear((int) $data['year']);
        }

        if (\array_key_exists('duration', $data)) {
            $film->setDuration((int) $data['duration']);
        }

        if (\array_key_exists('poster', $data)) {
            $film->setPoster($data['poster'] === null ? null : (string) $data['poster']);
        }

        if (\array_key_exists('trailerVideoId', $data)) {
            $film->setTrailerVideoId($data['trailerVideoId'] === null ? null : (string) $data['trailerVideoId']);
        }

        if (\array_key_exists('bunnyVideoId', $data)) {
            $film->setBunnyVideoId($data['bunnyVideoId'] === null ? null : (string) $data['bunnyVideoId']);
        }

        if (\array_key_exists('status', $data)) {
            $newStatus = (string) $data['status'];
            if (!\in_array($newStatus, self::ALLOWED_STATUSES, true)) {
                return $this->json(['message' => 'Statut invalide.'], 400);
            }
            $previous = $film->getStatus();
            $film->setStatus($newStatus);
            // Synchroniser les timestamps si bascule manuelle.
            // publishedAt n'est posé qu'à la première publication (une republication
            // après retrait garde la date d'origine) ; withdrawnAt à chaque retrait.
            if ($newStatus === Film::STATUS_PUBLISHED && $previous !== Film::STATUS_PUBLISHED && $film->getPublishedAt() === null) {
                $film->setPublishedAt(new \DateTimeImmutable());
            }
            if ($newStatus === Film::STATUS_WITHDRAWN && $previous !== Film::STATUS_WITHDRAWN) {
                $film->setWithdrawnAt(new \DateTimeImmutable());
            }
        }

        if (\array_key_exists('studioId', $data)) {
            if ($data['studioId'] === null) {
                $film->setStudio(null);
            } else {
                try {
                    $studioUuid = Uuid::fromString((string) $data['studioId']);
                } catch (\InvalidArgumentException) {
                    return $this->json(['message' => 'studioId invalide.'], 400);
                }
                $studio = $this->studioRepo->find($studioUuid);
                if ($studio === null) {
                    return $this->json(['message' => 'Studio introuvable.'], 404);
                }
                $film->setStudio($studio);
            }
        }

        $this->em->flush();

        return $this->json($this->serializeWithStudio($film, true));
    }

    /**
     * Supprime définitivement un film de la base (à distinguer du retrait
     * WITHDRAWN, qui le masque sans l'effacer). Les fichiers Bunny ne sont pas
     * supprimés.
     *
     * @return Response 204 sans contenu ; 400 UUID mal formé ; 404 film introuvable
     */
    #[Route('/{id}', name: 'admin_films_delete', methods: ['DELETE'])]
    public function delete(string $id): Response
    {
        $film = $this->resolveFilm($id);
        if ($film instanceof JsonResponse) {
            return $film;
        }

        $this->em->remove($film);
        $this->em->flush();

        return new Response('', 204);
    }

    /**
     * Parse l'UUID, charge le film, ou retourne JsonResponse 400/404.
     */
    private function resolveFilm(string $id): Film|JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            return $this->json(['message' => 'Identifiant invalide.'], 400);
        }

        $film = $this->filmRepo->find($uuid);
        if ($film === null) {
            return $this->json(['message' => 'Film introuvable.'], 404);
        }

        return $film;
    }

    /**
     * Sérialise un film avec le studio enrichi (résumé) plutôt que juste l'id.
     *
     * @param bool $expand true : relations complètes (vue détail) ; false : forme allégée (liste)
     */
    private function serializeWithStudio(Film $film, bool $expand = false): array
    {
        $row = $film->toArray($expand);
        $studio = $film->getStudio();
        $row['studio'] = $studio === null ? null : [
            'id' => $studio->getId()->toRfc4122(),
            'name' => $studio->getName(),
            'slug' => $studio->getSlug(),
        ];

        return $row;
    }
}
