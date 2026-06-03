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

    #[Route('/{id}', name: 'serie_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $serie = $this->series->find($id);
        if (!$serie || $serie->getStatus() !== Serie::STATUS_PUBLISHED) {
            return new JsonResponse(['error' => 'Serie not found'], 404);
        }
        return new JsonResponse($serie->toArray(true));
    }

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
