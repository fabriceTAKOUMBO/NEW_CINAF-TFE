<?php
namespace App\Controller;

use App\Entity\Episode;
use App\Entity\Film;
use App\Entity\Season;
use App\Entity\Serie;
use App\Entity\User;
use App\Repository\FilmRepository;
use App\Repository\SerieRepository;
use App\Service\BunnyCatalogueService;
use App\Service\BunnyZoneRegistry;
use App\Service\SubscriptionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Catalogue public exposé aux utilisateurs.
 *
 * Phase F (Agent 5) — Feature flag `CATALOGUE_SOURCE` :
 *  - `bunny` (default, rollback safe) : alimenté depuis la Storage Zone Bunny
 *    `cinaftv-movies` via {@see BunnyCatalogueService} (96 œuvres, HLS adaptatif).
 *  - `db` : alimenté depuis les entités Film/Serie publiées (status='PUBLISHED').
 *
 * Quel que soit la source, le contrat JSON est identique pour le frontend
 * (DiscoverWorkSummary / DiscoverWork) afin de ne pas casser le module
 * `discover` côté client.
 */
#[Route('/api/catalogue/discover')]
class CatalogueDiscoverController extends AbstractController
{
    private const SOURCE_BUNNY = 'bunny';
    private const SOURCE_DB = 'db';
    private const HLS_MANIFEST = 'master.m3u8';

    public function __construct(
        private readonly BunnyCatalogueService $catalogue,
        private readonly SubscriptionService $subService,
        private readonly FilmRepository $filmRepo,
        private readonly SerieRepository $serieRepo,
        private readonly BunnyZoneRegistry $zoneRegistry,
        private readonly string $catalogueSource = self::SOURCE_BUNNY,
        private readonly string $catalogueZone = 'cinaftv-movies',
    ) {}

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $q = $request->query->get('q');
        $kind = $request->query->get('kind');
        $page = (int) $request->query->get('page', 1);
        $limit = (int) $request->query->get('limit', 30);

        $kindFilter = in_array($kind, ['film', 'serie'], true) ? $kind : null;

        if ($this->catalogueSource === self::SOURCE_DB) {
            return $this->json($this->listFromDb(
                $q !== null ? (string) $q : null,
                $kindFilter,
                max(1, $page),
                max(1, min(100, $limit)),
            ));
        }

        try {
            $result = $this->catalogue->listWorks(
                $q !== null ? (string) $q : null,
                $kindFilter,
                $page,
                $limit,
            );
        } catch (\RuntimeException $e) {
            return $this->json([
                'message' => 'Catalogue Bunny indisponible.',
                'detail'  => $e->getMessage(),
            ], 502);
        }

        return $this->json($result);
    }

    /**
     * Vérifie si l'utilisateur connecté peut lire le contenu de l'œuvre :
     * - 204 No Content si abo actif
     * - 403 Forbidden si pas d'abo actif (avec message)
     * - 404 si l'œuvre n'existe pas
     */
    #[Route('/{slug}/can-play', methods: ['GET'], requirements: ['slug' => '[a-z0-9-]+'])]
    #[IsGranted('ROLE_USER')]
    public function canPlay(string $slug): JsonResponse
    {
        $exists = $this->workExists($slug);
        if ($exists === null) {
            // Erreur infra (Bunny indispo).
            return $this->json([
                'message' => 'Catalogue indisponible.',
            ], 502);
        }
        if ($exists === false) {
            return $this->json(['message' => "Œuvre '$slug' introuvable."], 404);
        }

        /** @var User $user */
        $user = $this->getUser();
        if (!$this->subService->hasActiveSubscription($user)) {
            return $this->json([
                'message' => 'Abonnement actif requis pour lire ce contenu.',
                'reason'  => 'no_subscription',
            ], 403);
        }

        return $this->json(null, 204);
    }

    #[Route('/{slug}', methods: ['GET'], requirements: ['slug' => '[a-z0-9-]+'])]
    public function detail(string $slug): JsonResponse
    {
        if ($this->catalogueSource === self::SOURCE_DB) {
            $work = $this->detailFromDb($slug);
            if ($work === null) {
                return $this->json(['message' => "Œuvre '$slug' introuvable."], 404);
            }
            return $this->json($work);
        }

        try {
            $work = $this->catalogue->getWork($slug);
        } catch (\RuntimeException $e) {
            return $this->json([
                'message' => 'Catalogue Bunny indisponible.',
                'detail'  => $e->getMessage(),
            ], 502);
        }

        if ($work === null) {
            return $this->json(['message' => "Œuvre '$slug' introuvable."], 404);
        }

        return $this->json($work);
    }

    // -----------------------------------------------------------------------
    // Source DB — mappers
    // -----------------------------------------------------------------------

    /**
     * @return array{data: list<array{slug:string,title:string,kind:string}>, total:int, page:int, limit:int}
     */
    private function listFromDb(?string $q, ?string $kindFilter, int $page, int $limit): array
    {
        $films = [];
        $totalFilms = 0;
        $series = [];
        $totalSeries = 0;

        if ($kindFilter === null || $kindFilter === 'film') {
            $films = $this->filmRepo->findAllPaginated(
                Film::STATUS_PUBLISHED,
                null,
                $q,
                $page,
                $limit,
            );
            $totalFilms = $this->filmRepo->countAll(Film::STATUS_PUBLISHED, null, $q);
        }
        if ($kindFilter === null || $kindFilter === 'serie') {
            $series = $this->serieRepo->findAllPaginated(
                Serie::STATUS_PUBLISHED,
                null,
                $q,
                $page,
                $limit,
            );
            $totalSeries = $this->serieRepo->countAll(Serie::STATUS_PUBLISHED, null, $q);
        }

        $data = [];
        foreach ($films as $f) {
            $data[] = ['slug' => $f->getSlug(), 'title' => $f->getTitle(), 'kind' => 'film'];
        }
        foreach ($series as $s) {
            $data[] = ['slug' => $s->getSlug(), 'title' => $s->getTitle(), 'kind' => 'serie'];
        }
        usort($data, fn($a, $b) => strcasecmp($a['title'], $b['title']));

        return [
            'data' => $data,
            'total' => $totalFilms + $totalSeries,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * @return array{slug:string,title:string,kind:string,seasons:list<array{slug:string,name:string,episodes:list<array{slug:string,name:string,hlsUrl:?string,mp4Url:?string}>}>}|null
     */
    private function detailFromDb(string $slug): ?array
    {
        $film = $this->filmRepo->findOneBy(['slug' => $slug, 'status' => Film::STATUS_PUBLISHED]);
        if ($film !== null) {
            return $this->mapFilmToDiscover($film);
        }
        $serie = $this->serieRepo->findOneBy(['slug' => $slug, 'status' => Serie::STATUS_PUBLISHED]);
        if ($serie !== null) {
            return $this->mapSerieToDiscover($serie);
        }
        return null;
    }

    /**
     * Indique si une œuvre existe (pour can-play).
     * Retourne true / false / null (null = erreur infra Bunny).
     */
    private function workExists(string $slug): ?bool
    {
        if ($this->catalogueSource === self::SOURCE_DB) {
            $film = $this->filmRepo->findOneBy(['slug' => $slug, 'status' => Film::STATUS_PUBLISHED]);
            if ($film !== null) {
                return true;
            }
            $serie = $this->serieRepo->findOneBy(['slug' => $slug, 'status' => Serie::STATUS_PUBLISHED]);
            return $serie !== null;
        }

        try {
            return $this->catalogue->getWork($slug) !== null;
        } catch (\RuntimeException) {
            return null;
        }
    }

    private function mapFilmToDiscover(Film $film): array
    {
        // Tentative de résolution dynamique de l'arborescence Bunny via le
        // catalogue. Motivation : la commande `app:catalogue:import-bunny`
        // peut classifier comme Film des œuvres qui sont en réalité des
        // séries multi-épisodes (ex : `LE_PROCCES` contient 89 sous-dossiers
        // `LE_PROCCES_EPISODE_XX/master.m3u8`). Dans ce cas, `bunnyVideoId`
        // pointe sur la racine et l'URL `racine/master.m3u8` répond 404.
        //
        // `BunnyCatalogueService::getWork()` connaît la vraie structure du
        // dossier Bunny (via `buildWorkStructure` + `listingIsEpisode`) et
        // produit directement la structure `seasons[].episodes[]` attendue
        // par le frontend. On le réutilise tel quel et on enrichit du studio
        // côté entité DB.
        //
        // Fallback : si Bunny est indisponible ou si le slug n'existe pas
        // côté Bunny (cas d'un film créé via le module Studio dont le slug
        // ne correspond pas à un dossier de `cinaftv-movies`), on retombe
        // sur l'ancien comportement (1 saison « Œuvre », 1 épisode `principal`,
        // hlsUrl construite depuis `Film::bunnyVideoId`).
        try {
            $bunnyWork = $this->catalogue->getWork($film->getSlug());
        } catch (\Throwable) {
            $bunnyWork = null;
        }

        if ($bunnyWork !== null && !empty($bunnyWork['seasons'])) {
            $seasons = $bunnyWork['seasons'];

            // Rétro-compatibilité URL : si l'œuvre n'a qu'une seule saison
            // « Œuvre » avec un seul épisode (vrai film flat type `A_bientot`),
            // on force le slug épisode à `principal` pour préserver les liens
            // existants `?ep=principal&s=principale` générés par la fiche film.
            if (count($seasons) === 1 && count($seasons[0]['episodes']) === 1) {
                $seasons[0]['episodes'][0]['slug'] = 'principal';
            }

            return [
                'slug' => $film->getSlug(),
                'title' => $film->getTitle(),
                'kind' => 'film',
                // Référence studio dérivée de l'entité DB — Bunny ne connaît
                // pas l'appartenance studio, on la rajoute systématiquement.
                'studio' => $this->mapStudioRef($film->getStudio()),
                'seasons' => $seasons,
            ];
        }

        // Fallback : ancien comportement (construction depuis bunnyVideoId).
        $bunnyPath = $film->getBunnyVideoId();
        $episodes = [];
        if ($bunnyPath !== null && $bunnyPath !== '') {
            $episodes[] = [
                'slug' => 'principal',
                'name' => $film->getTitle(),
                'hlsUrl' => $this->buildHlsUrl($bunnyPath),
                'mp4Url' => null,
            ];
        }
        return [
            'slug' => $film->getSlug(),
            'title' => $film->getTitle(),
            'kind' => 'film',
            // Référence studio (null en mode catalogue Bunny live, sinon dérivée de l'entité)
            // — permet à la page détail d'afficher « Publié par {studio} » avec lien vers la chaîne.
            'studio' => $this->mapStudioRef($film->getStudio()),
            'seasons' => [[
                'slug' => 'principale',
                'name' => 'Œuvre',
                'episodes' => $episodes,
            ]],
        ];
    }

    private function mapSerieToDiscover(Serie $serie): array
    {
        $seasons = [];
        foreach ($serie->getSeasons() as $season) {
            /** @var Season $season */
            $episodes = [];
            foreach ($season->getEpisodes() as $episode) {
                /** @var Episode $episode */
                $bunnyPath = $episode->getBunnyVideoId();
                $episodes[] = [
                    'slug' => $this->slugify(sprintf('e-%02d', $episode->getNumber())),
                    'name' => $episode->getTitle(),
                    'hlsUrl' => $bunnyPath ? $this->buildHlsUrl($bunnyPath) : null,
                    'mp4Url' => null,
                ];
            }
            $seasons[] = [
                'slug' => $this->slugify(sprintf('saison-%d', $season->getNumber())),
                'name' => $season->getTitle() ?? sprintf('Saison %d', $season->getNumber()),
                'episodes' => $episodes,
            ];
        }
        return [
            'slug' => $serie->getSlug(),
            'title' => $serie->getTitle(),
            'kind' => 'serie',
            // Cf. mapFilmToDiscover.
            'studio' => $this->mapStudioRef($serie->getStudio()),
            'seasons' => $seasons,
        ];
    }

    /**
     * Sérialise une référence légère vers le studio (id, name, slug, logoUrl)
     * pour les pages détail. Retourne null si l'œuvre n'a pas de studio rattaché
     * (cas du catalogue Bunny live qui ne passe pas par les entités DB).
     */
    private function mapStudioRef(?\App\Entity\Studio $studio): ?array
    {
        if ($studio === null) {
            return null;
        }
        return [
            'id' => $studio->getId()->toRfc4122(),
            'name' => $studio->getName(),
            'slug' => $studio->getSlug(),
            'logoUrl' => $studio->getLogoUrl(),
        ];
    }

    private function buildHlsUrl(string $bunnyPath): string
    {
        try {
            $storage = $this->zoneRegistry->get($this->catalogueZone);
        } catch (\InvalidArgumentException) {
            return '';
        }
        $path = trim($bunnyPath, '/');
        return $storage->getPublicUrl($path . '/' . self::HLS_MANIFEST);
    }

    private function slugify(string $name): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '');
        return trim($slug, '-') ?: 'item';
    }
}
