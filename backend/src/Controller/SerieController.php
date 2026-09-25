<?php
namespace App\Controller;

use App\Entity\Serie;
use App\Entity\Season;
use App\Repository\SerieRepository;
use App\Repository\SeasonRepository;
use App\Repository\GenreRepository;
use App\Repository\CountryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Catalogue public des séries (lecture en base de données) et opérations
 * d'administration, sous le préfixe /api/series :
 *  - GET    /api/series                                 liste paginée (public) ;
 *  - GET    /api/series/search                          recherche texte + filtres (public) ;
 *  - GET    /api/series/{id}                            fiche d'une série (public) ;
 *  - GET    /api/series/{id}/seasons                    saisons d'une série (public) ;
 *  - GET    /api/series/{id}/seasons/{number}/episodes  épisodes d'une saison (public) ;
 *  - PATCH  /api/series/{id}                            modification (ROLE_ADMIN) ;
 *  - DELETE /api/series/{id}                            suppression (ROLE_ADMIN).
 *
 * Mêmes règles que FilmController : les lectures publiques ne concernent que
 * les séries PUBLISHED (404, jamais 403, pour une série inexistante ou non
 * publiée) ; l'accès repose sur les `#[IsGranted]` des méthodes (aucune règle
 * access_control sur /api/series) ; /search est déclarée avant /{id} ; `{id}`
 * n'est pas validé (une valeur qui n'est pas un UUID provoque une erreur 500).
 */
#[Route('/api/series')]
class SerieController extends AbstractController
{
    public function __construct(
        private SerieRepository $series,
        private SeasonRepository $seasons,
        private GenreRepository $genres,
        private CountryRepository $countries,
        private EntityManagerInterface $em,
    ) {}

    /**
     * Liste paginée des séries PUBLISHED, plus récentes d'abord.
     *
     * Paramètres : `page` (défaut 1, ramené à ≥ 1), `limit` (défaut 30, borné à 1..100).
     *
     * @return JsonResponse 200 `{data: Serie::toArray(true)[], total, page, limit}`
     */
    #[Route('', name: 'serie_list', methods: ['GET'])]
    public function list(Request $req): JsonResponse
    {
        $page = max(1, (int) $req->query->get('page', 1));
        $limit = min(100, max(1, (int) $req->query->get('limit', 30)));
        $result = $this->series->findPaginated($page, $limit, Serie::STATUS_PUBLISHED);
        return new JsonResponse([
            'data' => array_map(fn(Serie $s) => $s->toArray(true), $result['data']),
            'total' => $result['total'],
            'page' => $result['page'],
            'limit' => $result['limit'],
        ]);
    }

    /**
     * Recherche parmi les séries PUBLISHED (SerieRepository::search()).
     *
     * Paramètres : `q` (texte cherché dans le titre et le synopsis, insensible
     * à la casse), filtres optionnels `genre` (nom ou slug) et `year`,
     * pagination `page` / `limit` comme list().
     *
     * @return JsonResponse 200 `{data: Serie::toArray(true)[], total, page, limit}`
     */
    #[Route('/search', name: 'serie_search', methods: ['GET'])]
    public function search(Request $req): JsonResponse
    {
        $q = $req->query->get('q');
        $filters = ['genre' => $req->query->get('genre'), 'year' => $req->query->get('year')];
        $page = max(1, (int) $req->query->get('page', 1));
        $limit = min(100, max(1, (int) $req->query->get('limit', 30)));
        $result = $this->series->search($q, $filters, $page, $limit, Serie::STATUS_PUBLISHED);
        return new JsonResponse([
            'data' => array_map(fn(Serie $s) => $s->toArray(true), $result['data']),
            'total' => $result['total'],
            'page' => $result['page'],
            'limit' => $result['limit'],
        ]);
    }

    /**
     * Fiche publique d'une série, avec ses saisons (sans leurs épisodes).
     *
     * @return JsonResponse 200 Serie::toArray(true) ; 404 `{error}` si la série
     *                      n'existe pas ou n'est pas PUBLISHED
     */
    #[Route('/{id}', name: 'serie_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $serie = $this->series->find($id);
        // Non publiée (brouillon, en attente, retirée) : même 404 qu'une série inexistante.
        if (!$serie || $serie->getStatus() !== Serie::STATUS_PUBLISHED) {
            return new JsonResponse(['error' => 'Serie not found'], 404);
        }
        return new JsonResponse($serie->toArray(true));
    }

    /**
     * Saisons d'une série PUBLISHED, par numéro croissant, sans leurs épisodes.
     *
     * @return JsonResponse 200 tableau de Season::toArray(false) (`{id, number, title, synopsis}`) ;
     *                      404 `{error}` si la série n'existe pas ou n'est pas PUBLISHED
     */
    #[Route('/{id}/seasons', name: 'serie_seasons', methods: ['GET'])]
    public function listSeasons(string $id): JsonResponse
    {
        $serie = $this->series->find($id);
        if (!$serie || $serie->getStatus() !== Serie::STATUS_PUBLISHED) {
            return new JsonResponse(['error' => 'Serie not found'], 404);
        }
        $data = array_map(fn(Season $s) => $s->toArray(false), $serie->getSeasons()->toArray());
        return new JsonResponse($data);
    }

    /**
     * Épisodes de la saison n° `$number` d'une série PUBLISHED, par numéro croissant.
     *
     * Accessible sans authentification alors que chaque épisode expose son
     * `bunnyVideoId` (Episode::toArray()) ; GET /api/episodes/{id}/stream,
     * lui, exige un utilisateur connecté.
     *
     * @param int $number numéro de la saison dans la série (pas son UUID)
     *
     * @return JsonResponse 200 tableau de Episode::toArray() ; 404 `{error}` si la
     *                      série n'existe pas ou n'est pas PUBLISHED, ou si la saison n'existe pas
     */
    #[Route('/{id}/seasons/{number}/episodes', name: 'serie_episodes', methods: ['GET'])]
    public function listEpisodes(string $id, int $number): JsonResponse
    {
        $serie = $this->series->find($id);
        if (!$serie || $serie->getStatus() !== Serie::STATUS_PUBLISHED) {
            return new JsonResponse(['error' => 'Serie not found'], 404);
        }
        $season = $this->seasons->findOneBy(['serie' => $serie, 'number' => $number]);
        if (!$season) {
            return new JsonResponse(['error' => 'Season not found'], 404);
        }
        return new JsonResponse(array_map(fn($ep) => $ep->toArray(), $season->getEpisodes()->toArray()));
    }

    // NB : la création de séries se fait exclusivement côté studio
    // (POST /api/studio/series, StudioSerieController) car toute série doit être
    // rattachée à un studio (serie.studio_id NOT NULL). L'ancien endpoint admin
    // POST /api/series a été retiré le 2026-06-03 (vestige Sprint 2 non utilisé,
    // qui renvoyait 500 faute d'assigner un studio).

    /**
     * Modification d'une série par un administrateur, quel que soit son statut
     * (le statut lui-même n'est pas modifiable ici).
     *
     * Corps JSON, tous champs facultatifs : `title`, `synopsis`, `year`
     * (ignorés s'ils sont absents ou null) ; `poster`, `trailerVideoId`
     * (appliqués dès que la clé est présente, même à null, ce qui permet de
     * les effacer) ; relations `genres` et `countries` (voir attachRelations()).
     *
     * @return JsonResponse 200 Serie::toArray(true) de la série modifiée ; 404 `{error}`
     *                      si introuvable ; 401 sans jeton, 403 si non admin
     */
    #[Route('/{id}', name: 'serie_patch', methods: ['PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    public function patch(string $id, Request $req): JsonResponse
    {
        $serie = $this->series->find($id);
        if (!$serie) {
            return new JsonResponse(['error' => 'Serie not found'], 404);
        }
        $data = json_decode($req->getContent(), true) ?? [];
        if (isset($data['title'])) { $serie->setTitle($data['title']); }
        if (isset($data['synopsis'])) { $serie->setSynopsis($data['synopsis']); }
        if (isset($data['year'])) { $serie->setYear((int) $data['year']); }
        if (array_key_exists('poster', $data)) { $serie->setPoster($data['poster']); }
        if (array_key_exists('trailerVideoId', $data)) { $serie->setTrailerVideoId($data['trailerVideoId']); }
        $this->attachRelations($serie, $data);
        $this->em->flush();
        return new JsonResponse($serie->toArray(true));
    }

    /**
     * Suppression définitive d'une série par un administrateur, quel que soit
     * son statut ; ses saisons et épisodes sont supprimés par cascade ORM.
     *
     * @return Response 204 sans corps ; 404 `{error}` si introuvable ;
     *                  401 sans jeton, 403 si non admin
     */
    #[Route('/{id}', name: 'serie_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(string $id): Response
    {
        $serie = $this->series->find($id);
        if (!$serie) {
            return new JsonResponse(['error' => 'Serie not found'], 404);
        }
        $this->em->remove($serie);
        $this->em->flush();
        return new Response('', 204);
    }

    /**
     * Remplace les genres et pays de la série à partir du corps d'un PATCH,
     * selon les mêmes règles que FilmController::attachRelations() : liste
     * vidée puis reconstruite si la clé est un tableau non vide, références
     * introuvables ignorées ; genre par UUID, slug ou nom, pays par UUID ou
     * code ISO. Même limite : une valeur qui n'est pas un UUID fait échouer
     * find() (erreur 500) avant les replis par slug, nom ou code ISO.
     *
     * @param array<string, mixed> $data corps JSON décodé de la requête
     */
    private function attachRelations(Serie $serie, array $data): void
    {
        if (!empty($data['genres']) && is_array($data['genres'])) {
            foreach ($serie->getGenres() as $g) { $serie->removeGenre($g); }
            foreach ($data['genres'] as $idOrSlug) {
                $g = $this->genres->find($idOrSlug) ?? $this->genres->findBySlug($idOrSlug) ?? $this->genres->findByName($idOrSlug);
                if ($g) { $serie->addGenre($g); }
            }
        }
        if (!empty($data['countries']) && is_array($data['countries'])) {
            foreach ($serie->getCountries() as $c) { $serie->removeCountry($c); }
            foreach ($data['countries'] as $idOrCode) {
                $c = $this->countries->find($idOrCode) ?? $this->countries->findByIsoCode($idOrCode);
                if ($c) { $serie->addCountry($c); }
            }
        }
    }
}
