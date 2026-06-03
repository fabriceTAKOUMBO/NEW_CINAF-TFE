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

    #[Route('', name: 'film_list', methods: ['GET'])]
    public function list(Request $req): JsonResponse
    {
        $page = max(1, (int) $req->query->get('page', 1));
        $limit = min(100, max(1, (int) $req->query->get('limit', 30)));
        $result = $this->films->findPaginated($page, $limit, Film::STATUS_PUBLISHED);
        return $this->paginated($result);
    }

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

    #[Route('/featured', name: 'film_featured', methods: ['GET'])]
    public function featuredList(): JsonResponse
    {
        $items = $this->featured->findActive();
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

    #[Route('/trending', name: 'film_trending', methods: ['GET'])]
    public function trending(Request $req): JsonResponse
    {
        $limit = min(50, max(1, (int) $req->query->get('limit', 10)));
        // Filtre status appliqué en SQL (cf. FilmRepository) : on obtient bien
        // `limit` films publiés, sans troncature en mémoire.
        $items = $this->films->findTrending($limit, Film::STATUS_PUBLISHED);
        return new JsonResponse(array_map(fn(Film $f) => $f->toArray(true), $items));
    }

    #[Route('/new', name: 'film_new', methods: ['GET'])]
    public function newReleases(Request $req): JsonResponse
    {
        $limit = min(50, max(1, (int) $req->query->get('limit', 10)));
        $items = $this->films->findNew($limit, Film::STATUS_PUBLISHED);
        return new JsonResponse(array_map(fn(Film $f) => $f->toArray(true), $items));
    }

    #[Route('/{id}', name: 'film_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $film = $this->films->find($id);
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

    #[Route('/{id}', name: 'film_patch', methods: ['PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    public function patch(string $id, Request $req): JsonResponse
    {
        $film = $this->films->find($id);
        if (!$film) {
            return new JsonResponse(['error' => 'Film not found'], 404);
        }
        $data = json_decode($req->getContent(), true) ?? [];
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
