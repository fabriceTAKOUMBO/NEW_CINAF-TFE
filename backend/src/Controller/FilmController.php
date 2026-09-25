<?php
namespace App\Controller;

use App\Entity\Film;
use App\Entity\FeaturedContent;
use App\Repository\FilmRepository;
use App\Repository\FeaturedContentRepository;
use App\Repository\GenreRepository;
use App\Repository\CountryRepository;
use App\Repository\PersonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Catalogue public des films (lecture en base de données) et quelques
 * opérations ponctuelles, sous le préfixe /api/films :
 *  - GET    /api/films               liste paginée (public) ;
 *  - GET    /api/films/search        recherche texte + filtres (public) ;
 *  - GET    /api/films/featured      films « à la une » (public) ;
 *  - GET    /api/films/trending      films les plus vus (public) ;
 *  - GET    /api/films/new           derniers films ajoutés (public) ;
 *  - GET    /api/films/{id}          fiche d'un film (public) ;
 *  - PATCH  /api/films/{id}          modification (ROLE_ADMIN) ;
 *  - DELETE /api/films/{id}          suppression (ROLE_ADMIN) ;
 *  - POST   /api/films/{id}/view     +1 vue (utilisateur connecté) ;
 *  - GET    /api/films/{id}/stream   infos de lecture Bunny (utilisateur connecté).
 *
 * Visibilité : les lectures publiques ne renvoient que des films PUBLISHED ;
 * un film inexistant ou non publié donne 404 (jamais 403), pour ne pas
 * révéler l'existence d'un brouillon. Aucune règle access_control ne couvre
 * /api/films : l'accès repose sur les `#[IsGranted]` des méthodes. Les routes
 * fixes (/search, /featured, /trending, /new) sont déclarées avant /{id},
 * sinon elles seraient capturées comme un identifiant.
 *
 * Limite : `{id}` n'est pas validé ; une valeur qui n'est pas un UUID fait
 * échouer find() (exception de conversion Doctrine, réponse 500) au lieu d'un 404.
 */
#[Route('/api/films')]
class FilmController extends AbstractController
{
    public function __construct(
        private FilmRepository $films,
        private FeaturedContentRepository $featured,
        private GenreRepository $genres,
        private CountryRepository $countries,
        private PersonRepository $persons,
        private EntityManagerInterface $em,
        // Identifiant de la Bunny Stream Library, injecté depuis l'environnement
        // (via le conteneur Symfony) plutôt que lu directement dans $_ENV.
        #[Autowire(env: 'BUNNY_STREAM_LIBRARY_ID')]
        private readonly string $bunnyStreamLibraryId,
    ) {}

    /**
     * Liste paginée des films PUBLISHED, plus récents d'abord.
     *
     * Paramètres : `page` (défaut 1, ramené à ≥ 1), `limit` (défaut 30, borné à 1..100).
     *
     * @return JsonResponse 200 `{data: Film::toArray(true)[], total, page, limit}`
     */
    #[Route('', name: 'film_list', methods: ['GET'])]
    public function list(Request $req): JsonResponse
    {
        $page = max(1, (int) $req->query->get('page', 1));
        $limit = min(100, max(1, (int) $req->query->get('limit', 30)));
        $result = $this->films->findPaginated($page, $limit, Film::STATUS_PUBLISHED);
        return $this->paginated($result);
    }

    /**
     * Recherche parmi les films PUBLISHED (FilmRepository::search()).
     *
     * Paramètres : `q` (texte cherché dans le titre et le synopsis, insensible
     * à la casse), filtres optionnels `genre` (nom ou slug), `year`, `country`
     * (nom ou code ISO), pagination `page` / `limit` comme list(). Le
     * paramètre `lang` est transmis mais ignoré par le repository (aucune
     * relation entre Film et Language).
     *
     * @return JsonResponse 200 `{data: Film::toArray(true)[], total, page, limit}`
     */
    #[Route('/search', name: 'film_search', methods: ['GET'])]
    public function search(Request $req): JsonResponse
    {
        $q = $req->query->get('q');
        $filters = [
            'genre' => $req->query->get('genre'),
            'year' => $req->query->get('year'),
            'country' => $req->query->get('country'),
            'lang' => $req->query->get('lang'),
        ];
        $page = max(1, (int) $req->query->get('page', 1));
        $limit = min(100, max(1, (int) $req->query->get('limit', 30)));
        $result = $this->films->search($q, $filters, $page, $limit, Film::STATUS_PUBLISHED);
        return $this->paginated($result);
    }

    /**
     * Films « à la une » : mises en avant en cours
     * (FeaturedContentRepository::findActive()), dans l'ordre de `position`.
     * Les séries mises en avant et les films non PUBLISHED sont écartés.
     *
     * @return JsonResponse 200 tableau de Film::toArray(true) (les films
     *                      eux-mêmes, pas les objets FeaturedContent)
     */
    #[Route('/featured', name: 'film_featured', methods: ['GET'])]
    public function featuredList(): JsonResponse
    {
        $items = $this->featured->findActive();
        // array_map renvoie null pour chaque entrée écartée, array_filter
        // retire ces null et array_values réindexe (tableau JSON, pas objet).
        $data = array_values(array_filter(array_map(
            function (FeaturedContent $fc) {
                $film = $fc->getFilm();
                if ($film === null || $film->getStatus() !== Film::STATUS_PUBLISHED) {
                    return null;
                }
                return $film->toArray(true);
            },
            $items
        )));
        return new JsonResponse($data);
    }

    /**
     * Films PUBLISHED les plus vus (compteur `views` décroissant).
     *
     * Paramètre : `limit` (défaut 10, borné à 1..50).
     *
     * @return JsonResponse 200 tableau de Film::toArray(true)
     */
    #[Route('/trending', name: 'film_trending', methods: ['GET'])]
    public function trending(Request $req): JsonResponse
    {
        $limit = min(50, max(1, (int) $req->query->get('limit', 10)));
        // Filtre status appliqué en SQL (cf. FilmRepository) : on obtient bien
        // `limit` films publiés, sans troncature en mémoire.
        $items = $this->films->findTrending($limit, Film::STATUS_PUBLISHED);
        return new JsonResponse(array_map(fn(Film $f) => $f->toArray(true), $items));
    }

    /**
     * Derniers films PUBLISHED ajoutés (date de création en base décroissante).
     *
     * Paramètre : `limit` (défaut 10, borné à 1..50).
     *
     * @return JsonResponse 200 tableau de Film::toArray(true)
     */
    #[Route('/new', name: 'film_new', methods: ['GET'])]
    public function newReleases(Request $req): JsonResponse
    {
        $limit = min(50, max(1, (int) $req->query->get('limit', 10)));
        $items = $this->films->findNew($limit, Film::STATUS_PUBLISHED);
        return new JsonResponse(array_map(fn(Film $f) => $f->toArray(true), $items));
    }

    /**
     * Fiche publique d'un film.
     *
     * @return JsonResponse 200 Film::toArray(true) ; 404 `{error}` si le film
     *                      n'existe pas ou n'est pas PUBLISHED
     */
    #[Route('/{id}', name: 'film_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $film = $this->films->find($id);
        // Brouillon, en attente d'approbation ou retiré : même 404 qu'un film
        // inexistant, pour ne pas révéler son existence.
        if (!$film || $film->getStatus() !== Film::STATUS_PUBLISHED) {
            return new JsonResponse(['error' => 'Film not found'], 404);
        }
        return new JsonResponse($film->toArray(true));
    }

    // NB : la création de films se fait exclusivement côté studio
    // (POST /api/studio/films, StudioFilmController) car tout film doit être
    // rattaché à un studio (film.studio_id NOT NULL). L'ancien endpoint admin
    // POST /api/films a été retiré le 2026-06-03 (vestige Sprint 2 non utilisé,
    // qui renvoyait 500 faute d'assigner un studio).

    /**
     * Modification d'un film par un administrateur, quel que soit son statut
     * (le statut lui-même n'est pas modifiable ici).
     *
     * Corps JSON, tous champs facultatifs : `title`, `synopsis`, `year`,
     * `duration` (ignorés s'ils sont absents ou null) ; `poster`,
     * `trailerVideoId`, `bunnyVideoId` (appliqués dès que la clé est
     * présente, même à null, ce qui permet de les effacer) ; relations
     * `genres`, `countries`, `directors`, `cast` (voir attachRelations()).
     * Aucune validation des valeurs au-delà du transtypage en entier.
     *
     * @return JsonResponse 200 Film::toArray(true) du film modifié ; 404 `{error}`
     *                      si introuvable ; 401 sans jeton, 403 si non admin
     */
    #[Route('/{id}', name: 'film_patch', methods: ['PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    public function patch(string $id, Request $req): JsonResponse
    {
        $film = $this->films->find($id);
        if (!$film) {
            return new JsonResponse(['error' => 'Film not found'], 404);
        }
        $data = json_decode($req->getContent(), true) ?? [];
        // isset() ignore les valeurs null ; array_key_exists() (plus bas)
        // les accepte, pour pouvoir vider un champ média.
        if (isset($data['title'])) { $film->setTitle($data['title']); }
        if (isset($data['synopsis'])) { $film->setSynopsis($data['synopsis']); }
        if (isset($data['year'])) { $film->setYear((int) $data['year']); }
        if (isset($data['duration'])) { $film->setDuration((int) $data['duration']); }
        if (array_key_exists('poster', $data)) { $film->setPoster($data['poster']); }
        if (array_key_exists('trailerVideoId', $data)) { $film->setTrailerVideoId($data['trailerVideoId']); }
        if (array_key_exists('bunnyVideoId', $data)) { $film->setBunnyVideoId($data['bunnyVideoId']); }

        $this->attachRelations($film, $data);
        $this->em->flush();
        return new JsonResponse($film->toArray(true));
    }

    /**
     * Suppression définitive d'un film par un administrateur, quel que soit
     * son statut.
     *
     * @return Response 204 sans corps ; 404 `{error}` si introuvable ;
     *                  401 sans jeton, 403 si non admin
     */
    #[Route('/{id}', name: 'film_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(string $id): Response
    {
        $film = $this->films->find($id);
        if (!$film) {
            return new JsonResponse(['error' => 'Film not found'], 404);
        }
        $this->em->remove($film);
        $this->em->flush();
        return new Response('', 204);
    }

    /**
     * Ajoute une vue au compteur du film (qui alimente /api/films/trending).
     *
     * Chaque appel compte une vue, sans dédoublonnage. Le statut n'est pas
     * vérifié : contrairement à get(), un film non publié est aussi comptabilisé.
     *
     * @return Response 204 sans corps ; 404 `{error}` si le film n'existe pas ; 401 sans jeton
     */
    #[Route('/{id}/view', name: 'film_view', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function incrementView(string $id): Response
    {
        $film = $this->films->find($id);
        if (!$film) {
            return new JsonResponse(['error' => 'Film not found'], 404);
        }
        $film->incrementViews();
        $this->em->flush();
        return new Response('', 204);
    }

    /**
     * Informations de lecture d'un film pour le lecteur vidéo (nom de méthode
     * streamInfo() : `stream()` entrerait en collision avec AbstractController).
     *
     * Renvoie le chemin Bunny de la vidéo et l'identifiant de la Bunny Stream
     * Library. Seule une authentification est exigée : aucun contrôle
     * d'abonnement payant ici et, contrairement à EpisodeController::streamInfo(),
     * aucun contrôle du statut PUBLISHED.
     *
     * @return JsonResponse 200 `{bunnyVideoId, libraryId}` ; 404 `{error}` si le
     *                      film n'existe pas ; 401 sans jeton
     */
    #[Route('/{id}/stream', name: 'film_stream', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function streamInfo(string $id): JsonResponse
    {
        $film = $this->films->find($id);
        if (!$film) {
            return new JsonResponse(['error' => 'Film not found'], 404);
        }
        return new JsonResponse([
            'bunnyVideoId' => $film->getBunnyVideoId(),
            'libraryId' => $this->bunnyStreamLibraryId,
        ]);
    }

    /**
     * Remplace les relations du film à partir du corps d'un PATCH.
     *
     * Pour chaque clé fournie sous forme de tableau NON vide (`genres`,
     * `countries`, `directors`, `cast`), la liste existante est vidée puis
     * reconstruite ; une clé absente ou un tableau vide laisse la relation
     * inchangée (impossible donc de tout retirer). Les références introuvables
     * sont ignorées sans erreur. Formes prévues : genre par UUID, slug ou nom ;
     * pays par UUID ou code ISO ; personne par UUID.
     *
     * Limite (lecture du code) : find() convertit la valeur en UUID ; une
     * valeur qui n'est pas un UUID valide (slug, nom, code ISO) y lève une
     * exception de conversion Doctrine (réponse 500) avant d'atteindre les
     * replis findBySlug() / findByName() / findByIsoCode().
     *
     * @param array<string, mixed> $data corps JSON décodé de la requête
     */
    private function attachRelations(Film $film, array $data): void
    {
        if (!empty($data['genres']) && is_array($data['genres'])) {
            foreach ($film->getGenres() as $g) { $film->removeGenre($g); }
            foreach ($data['genres'] as $idOrSlug) {
                $g = $this->genres->find($idOrSlug) ?? $this->genres->findBySlug($idOrSlug) ?? $this->genres->findByName($idOrSlug);
                if ($g) { $film->addGenre($g); }
            }
        }
        if (!empty($data['countries']) && is_array($data['countries'])) {
            foreach ($film->getCountries() as $c) { $film->removeCountry($c); }
            foreach ($data['countries'] as $idOrCode) {
                $c = $this->countries->find($idOrCode) ?? $this->countries->findByIsoCode($idOrCode);
                if ($c) { $film->addCountry($c); }
            }
        }
        if (!empty($data['directors']) && is_array($data['directors'])) {
            foreach ($film->getDirectors() as $p) { $film->removeDirector($p); }
            foreach ($data['directors'] as $pid) {
                $p = $this->persons->find($pid);
                if ($p) { $film->addDirector($p); }
            }
        }
        if (!empty($data['cast']) && is_array($data['cast'])) {
            foreach ($film->getCast() as $p) { $film->removeCastMember($p); }
            foreach ($data['cast'] as $pid) {
                $p = $this->persons->find($pid);
                if ($p) { $film->addCastMember($p); }
            }
        }
    }

    /**
     * Met en forme un résultat paginé de FilmRepository (films en vue étendue).
     *
     * @param array{data: Film[], total: int, page: int, limit: int} $result
     *
     * @return JsonResponse 200 `{data: Film::toArray(true)[], total, page, limit}`
     */
    private function paginated(array $result): JsonResponse
    {
        return new JsonResponse([
            'data' => array_map(fn(Film $f) => $f->toArray(true), $result['data']),
            'total' => $result['total'],
            'page' => $result['page'],
            'limit' => $result['limit'],
        ]);
    }
}
