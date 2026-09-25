<?php

namespace App\Admin\Controller;

use App\Entity\Film;
use App\Entity\Serie;
use App\Studio\Service\ContentLifecycleService;
use App\Repository\FilmRepository;
use App\Repository\SerieRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Workflow d'approbation du premier contenu d'un studio (parcours
 * self-service) :
 *
 *   - `GET /api/admin/approvals` : liste paginée combinée films + séries
 *     en `PENDING_APPROVAL`. Choix d'un endpoint unique plutôt que deux
 *     séparés pour simplifier la tuile "Approbations" du dashboard
 *     admin (un seul appel).
 *   - `PATCH /api/admin/films/{id}/approve` et série : passe le contenu
 *     en `PUBLISHED` ET marque le studio comme validé. Idempotence :
 *     si déjà publié, on renvoie 409.
 *   - `PATCH /api/admin/films/{id}/reject` et série : repasse le
 *     contenu en `DRAFT`. Le studio reste non validé : il peut modifier
 *     et resoumettre. Le body accepte un champ `reason` optionnel
 *     (texte libre, non persisté — pas d'entité dédiée demandée).
 *
 * Contexte : un studio non validé (`Studio.isValidated = false`) qui publie
 * son premier contenu le voit passer en PENDING_APPROVAL au lieu de PUBLISHED
 * (ContentLifecycleService::publishFilm()/publishSerie()). Une fois un contenu
 * approuvé, le studio est validé et ses publications suivantes sont directes.
 *
 * Accès : ROLE_ADMIN (`#[IsGranted]` + `access_control` sur `^/api/admin`).
 * Les routes approve/reject acceptent PATCH ou POST et renvoient 409 pour tout
 * contenu qui n'est pas en PENDING_APPROVAL (brouillon, publié ou retiré).
 * Les transitions elles-mêmes sont déléguées à ContentLifecycleService.
 */
#[Route('/api/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminApprovalController extends AbstractController
{
    public function __construct(
        private readonly FilmRepository $filmRepo,
        private readonly SerieRepository $serieRepo,
        private readonly ContentLifecycleService $lifecycle,
        private readonly Connection $connection,
    ) {
    }

    /**
     * Liste paginée des contenus en attente d'approbation (films + séries),
     * triés par date de mise à jour décroissante. Format :
     *
     *   {
     *     data:  [{ kind: 'film'|'serie', ...toArray() }, ...],
     *     total: int,
     *     page:  int,
     *     limit: int,
     *   }
     *
     * Pagination faite côté base : un UNION ALL (film + serie filtrés sur
     * PENDING_APPROVAL) trié par `updated_at` DESC avec LIMIT/OFFSET récupère
     * uniquement les identifiants de la page courante, puis les entités sont
     * rechargées. On ne charge donc jamais toute la file en mémoire.
     *
     * Paramètres de requête : `page` (défaut 1) et `limit` (défaut 20, borné à 1..100).
     * Chaque élément porte aussi un résumé de son studio (`studio`).
     *
     * @return JsonResponse 200 `{data, total, page, limit}`
     */
    #[Route('/approvals', name: 'admin_approvals_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));
        $offset = ($page - 1) * $limit;

        // Total = nombre de films + séries en attente (deux COUNT en une requête).
        $total = (int) $this->connection->fetchOne(
            'SELECT (SELECT COUNT(*) FROM film WHERE status = :s1)
                  + (SELECT COUNT(*) FROM serie WHERE status = :s2)',
            ['s1' => Film::STATUS_PENDING_APPROVAL, 's2' => Serie::STATUS_PENDING_APPROVAL],
        );

        // Identifiants de la page courante, triés globalement par date de MAJ.
        $refs = $this->connection->fetchAllAssociative(
            "SELECT id, 'film' AS kind, updated_at FROM film WHERE status = :s1
             UNION ALL
             SELECT id, 'serie' AS kind, updated_at FROM serie WHERE status = :s2
             ORDER BY updated_at DESC
             LIMIT :limit OFFSET :offset",
            [
                's1' => Film::STATUS_PENDING_APPROVAL,
                's2' => Serie::STATUS_PENDING_APPROVAL,
                'limit' => $limit,
                'offset' => $offset,
            ],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        // Rechargement des entités de la page (ordre SQL préservé).
        $data = [];
        foreach ($refs as $ref) {
            if ($ref['kind'] === 'film') {
                $film = $this->filmRepo->find($ref['id']);
                if ($film !== null) {
                    $data[] = array_merge(
                        ['kind' => 'film'],
                        $film->toArray(true),
                        ['studio' => $this->serializeStudioSummary($film->getStudio())],
                    );
                }
            } else {
                $serie = $this->serieRepo->find($ref['id']);
                if ($serie !== null) {
                    $data[] = array_merge(
                        ['kind' => 'serie'],
                        $serie->toArray(true),
                        ['studio' => $this->serializeStudioSummary($serie->getStudio())],
                    );
                }
            }
        }

        return new JsonResponse([
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * Approuve un film en attente : il passe PUBLISHED (date de publication = maintenant)
     * et son studio devient validé (ContentLifecycleService::approveFilm()).
     *
     * @return JsonResponse 200 film détaillé (`Film::toArray(true)`) ; 400 UUID mal formé ;
     *                      404 film introuvable ; 409 film pas en PENDING_APPROVAL
     */
    #[Route('/films/{id}/approve', name: 'admin_films_approve', methods: ['PATCH', 'POST'])]
    public function approveFilm(string $id): JsonResponse
    {
        $film = $this->resolveFilm($id);
        if ($film instanceof JsonResponse) {
            return $film;
        }
        if ($film->getStatus() !== Film::STATUS_PENDING_APPROVAL) {
            return new JsonResponse(
                ['message' => "Ce film n'est pas en attente d'approbation."],
                409,
            );
        }
        $this->lifecycle->approveFilm($film);

        return new JsonResponse($film->toArray(true));
    }

    /**
     * Refuse un film en attente : il repasse en DRAFT, modifiable par le studio,
     * qui reste non validé (ContentLifecycleService::rejectFilm()).
     *
     * @return JsonResponse 200 film détaillé (`Film::toArray(true)`) ; 400 UUID mal formé ;
     *                      404 film introuvable ; 409 film pas en PENDING_APPROVAL
     */
    #[Route('/films/{id}/reject', name: 'admin_films_reject', methods: ['PATCH', 'POST'])]
    public function rejectFilm(string $id): JsonResponse
    {
        $film = $this->resolveFilm($id);
        if ($film instanceof JsonResponse) {
            return $film;
        }
        if ($film->getStatus() !== Film::STATUS_PENDING_APPROVAL) {
            return new JsonResponse(
                ['message' => "Ce film n'est pas en attente d'approbation."],
                409,
            );
        }
        // NB : une éventuelle raison de refus envoyée dans le corps n'est pas
        // persistée (pas d'entité dédiée à ce jour), on ne la lit donc pas.
        $this->lifecycle->rejectFilm($film);

        return new JsonResponse($film->toArray(true));
    }

    /**
     * Approuve une série en attente : elle passe PUBLISHED et son studio devient
     * validé (ContentLifecycleService::approveSerie()).
     *
     * @return JsonResponse 200 série détaillée (`Serie::toArray(true)`) ; 400 UUID mal formé ;
     *                      404 série introuvable ; 409 série pas en PENDING_APPROVAL
     */
    #[Route('/series/{id}/approve', name: 'admin_series_approve', methods: ['PATCH', 'POST'])]
    public function approveSerie(string $id): JsonResponse
    {
        $serie = $this->resolveSerie($id);
        if ($serie instanceof JsonResponse) {
            return $serie;
        }
        if ($serie->getStatus() !== Serie::STATUS_PENDING_APPROVAL) {
            return new JsonResponse(
                ['message' => "Cette série n'est pas en attente d'approbation."],
                409,
            );
        }
        $this->lifecycle->approveSerie($serie);

        return new JsonResponse($serie->toArray(true));
    }

    /**
     * Refuse une série en attente : elle repasse en DRAFT, le studio reste non
     * validé (ContentLifecycleService::rejectSerie()).
     *
     * @return JsonResponse 200 série détaillée (`Serie::toArray(true)`) ; 400 UUID mal formé ;
     *                      404 série introuvable ; 409 série pas en PENDING_APPROVAL
     */
    #[Route('/series/{id}/reject', name: 'admin_series_reject', methods: ['PATCH', 'POST'])]
    public function rejectSerie(string $id): JsonResponse
    {
        $serie = $this->resolveSerie($id);
        if ($serie instanceof JsonResponse) {
            return $serie;
        }
        if ($serie->getStatus() !== Serie::STATUS_PENDING_APPROVAL) {
            return new JsonResponse(
                ['message' => "Cette série n'est pas en attente d'approbation."],
                409,
            );
        }
        // NB : une éventuelle raison de refus envoyée dans le corps n'est pas
        // persistée (pas d'entité dédiée à ce jour), on ne la lit donc pas.
        $this->lifecycle->rejectSerie($serie);

        return new JsonResponse($serie->toArray(true));
    }

    /**
     * Parse l'UUID et charge le film, ou renvoie la JsonResponse d'erreur (400/404).
     */
    private function resolveFilm(string $id): Film|JsonResponse
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

        return $film;
    }

    /**
     * Parse l'UUID et charge la série, ou renvoie la JsonResponse d'erreur (400/404).
     */
    private function resolveSerie(string $id): Serie|JsonResponse
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

        return $serie;
    }

    /**
     * Résumé du studio (id, nom, slug, isValidated) joint à chaque élément de la
     * file d'approbation ; null si le contenu n'est rattaché à aucun studio.
     */
    private function serializeStudioSummary(?\App\Entity\Studio $studio): ?array
    {
        if ($studio === null) {
            return null;
        }

        return [
            'id' => $studio->getId()->toRfc4122(),
            'name' => $studio->getName(),
            'slug' => $studio->getSlug(),
            'isValidated' => $studio->isValidated(),
        ];
    }
}
