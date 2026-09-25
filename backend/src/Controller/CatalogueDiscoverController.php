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
 *    `cinaftv-movies` via {@see BunnyCatalogueService} (96 œuvres lors de la
 *    Phase F, HLS adaptatif).
 *  - `db` : alimenté depuis les entités Film/Serie publiées (status='PUBLISHED').
 *
 * Quelle que soit la source, le contrat JSON est identique pour le frontend
 * (DiscoverWorkSummary / DiscoverWork) afin de ne pas casser le module
 * `discover` côté client. La source `db` y ajoute des champs facultatifs
 * (affiche, studio, synopsis, année, genres, bande-annonce…) que la source
 * `bunny` ne connaît pas.
 *
 * Préfixe `/api/catalogue/discover`, accès public (aucune règle
 * access_control), sauf `can-play` qui exige un utilisateur connecté :
 *  - GET `/`               : liste paginée (`q`, `kind`, `page`, `limit`) ;
 *  - GET `/{slug}`         : fiche d'une œuvre avec saisons, épisodes et URL HLS ;
 *  - GET `/{slug}/can-play`: droit de lecture (abonnement payant actif), ROLE_USER.
 *
 * En source `db`, seules les œuvres PUBLISHED sont exposées (la source
 * `bunny` expose tout le contenu de la zone) ; la fiche d'une œuvre retirée
 * (WITHDRAWN) répond 410 pour que le front affiche une page « contenu
 * retiré ». `can-play` se contente de répondre oui / non : les URL HLS de la
 * fiche sont renvoyées à tout visiteur.
 */
#[Route('/api/catalogue/discover')]
class CatalogueDiscoverController extends AbstractController
{
    private const SOURCE_BUNNY = 'bunny';
    private const SOURCE_DB = 'db';
    private const HLS_MANIFEST = 'master.m3u8';
    /** Durée (s) de réutilisation des réponses publiques par le navigateur / CDN. */
    private const PUBLIC_CACHE_TTL = 60;

    /**
     * Synopsis posé par `app:catalogue:import-bunny` sur les œuvres importées.
     * Il n'a rien d'éditorial : la fiche publique le masque (null) plutôt que
     * de l'afficher aux visiteurs.
     */
    private const IMPORT_PLACEHOLDER_SYNOPSIS = 'Importé depuis le catalogue Bunny CINAF.';

    /**
     * @param string $catalogueSource Valeur de `CATALOGUE_SOURCE` (`bunny` ou `db`), injectée par
     *                                `config/services.yaml` ; toute autre valeur se comporte comme `bunny`.
     * @param string $catalogueZone   Zone Bunny des vidéos du catalogue, utilisée pour construire les URL HLS
     *                                en source `db`.
     */
    public function __construct(
        private readonly BunnyCatalogueService $catalogue,
        private readonly SubscriptionService $subService,
        private readonly FilmRepository $filmRepo,
        private readonly SerieRepository $serieRepo,
        private readonly BunnyZoneRegistry $zoneRegistry,
        private readonly string $catalogueSource = self::SOURCE_BUNNY,
        private readonly string $catalogueZone = 'cinaftv-movies',
    ) {}

    /**
     * Liste paginée des œuvres du catalogue « Découvrir ».
     *
     * Paramètres de requête : `q` (recherche dans le titre), `kind` (`film` ou
     * `serie`, toute autre valeur est ignorée), `page` (défaut 1) et `limit`
     * (défaut 30, borné entre 1 et 100).
     *
     * @return JsonResponse 200 `{data: [{slug, title, kind, poster?}], total, page, limit}`
     *                      (cache public 60 s) ; 502 `{message, detail}` si Bunny est
     *                      indisponible (source `bunny` uniquement).
     */
    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $q = $request->query->get('q');
        $kind = $request->query->get('kind');
        $page = (int) $request->query->get('page', 1);
        $limit = (int) $request->query->get('limit', 30);

        $kindFilter = in_array($kind, ['film', 'serie'], true) ? $kind : null;

        // Source db : mêmes bornes que listWorks() (page ≥ 1, 1 ≤ limit ≤ 100).
        if ($this->catalogueSource === self::SOURCE_DB) {
            return $this->publicCache($this->json($this->listFromDb(
                $q !== null ? (string) $q : null,
                $kindFilter,
                max(1, $page),
                max(1, min(100, $limit)),
            )));
        }

        // Source bunny : page et limit sont bornées par listWorks() lui-même.
        try {
            $result = $this->catalogue->listWorks(
                $q !== null ? (string) $q : null,
                $kindFilter,
                $page,
                $limit,
            );
        } catch (\RuntimeException $e) {
            // Pas de cache public sur une erreur : le prochain appel retentera Bunny.
            return $this->json([
                'message' => 'Catalogue Bunny indisponible.',
                'detail'  => $e->getMessage(),
            ], 502);
        }

        return $this->publicCache($this->json($result));
    }

    /**
     * Le catalogue public est identique pour tous les visiteurs : on autorise
     * navigateur et CDN à réutiliser la réponse 60 s. Sur un aller-retour
     * home → fiche → home, le navigateur ne rappelle plus l'API du tout.
     * Pas de cache sur `can-play` (dépend de l'utilisateur) ni sur les erreurs.
     */
    private function publicCache(JsonResponse $response): JsonResponse
    {
        $response->setPublic();
        $response->setMaxAge(self::PUBLIC_CACHE_TTL);
        $response->setSharedMaxAge(self::PUBLIC_CACHE_TTL);
        return $response;
    }

    /**
     * Vérifie si l'utilisateur connecté peut lire le contenu de l'œuvre :
     * - 204 No Content si abo actif
     * - 403 Forbidden si pas d'abo actif (avec message)
     * - 404 si l'œuvre n'existe pas
     * - 502 si le catalogue Bunny est injoignable (source `bunny`)
     * - 401 sans jeton JWT valide (#[IsGranted('ROLE_USER')])
     *
     * L'existence de l'œuvre est testée AVANT l'abonnement. Le 403 porte
     * `reason: "no_subscription"` pour que le client distingue ce cas.
     * « Abonnement actif » = abonnement payant (`Subscription`), pas le suivi
     * gratuit d'un studio.
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

    /**
     * Fiche publique d'une œuvre : métadonnées, saisons et épisodes avec URL
     * de lecture HLS. En source `db`, un film est présenté comme une œuvre à
     * une seule saison (`principale`) dont les épisodes sont ses parties.
     *
     * @return JsonResponse 200 avec l'œuvre (cache public 60 s) ; 410 `{message, status,
     *                      kind, title}` si l'œuvre a été retirée de la plateforme
     *                      (source `db`) ; 404 `{message}` si le slug est inconnu ou si
     *                      l'œuvre n'a jamais été publiée ; 502 `{message, detail}` si
     *                      Bunny est indisponible (source `bunny`).
     */
    #[Route('/{slug}', methods: ['GET'], requirements: ['slug' => '[a-z0-9-]+'])]
    public function detail(string $slug): JsonResponse
    {
        if ($this->catalogueSource === self::SOURCE_DB) {
            $work = $this->detailFromDb($slug);
            if ($work === null) {
                // Œuvre retirée (WITHDRAWN) : 410 Gone plutôt que 404, pour que le
                // front affiche une page « contenu retiré » à qui suit un ancien lien.
                // Elle a été publique : révéler son titre ne divulgue rien. Un
                // brouillon ou un contenu en attente d'approbation, jamais public,
                // reste en 404 : son existence ne doit pas transparaître.
                $withdrawn = $this->findWithdrawn($slug);
                if ($withdrawn !== null) {
                    return $this->json([
                        'message' => "Œuvre '$slug' retirée de la plateforme.",
                        'status' => 'WITHDRAWN',
                        'kind' => $withdrawn['kind'],
                        'title' => $withdrawn['title'],
                    ], 410);
                }
                return $this->json(['message' => "Œuvre '$slug' introuvable."], 404);
            }
            return $this->publicCache($this->json($work));
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

        return $this->publicCache($this->json($work));
    }

    // -----------------------------------------------------------------------
    // Source DB — mappers
    // -----------------------------------------------------------------------

    /**
     * Liste « Découvrir » en source `db` : films et séries au statut
     * PUBLISHED, filtrés par titre et par type.
     *
     * Sans filtre de type, les deux requêtes sont paginées séparément (tri par
     * date de création décroissante) puis fusionnées : une page peut donc
     * contenir jusqu'à `limit` films ET `limit` séries, et le tri alphabétique
     * final ne vaut qu'à l'intérieur de la page. `total` = films + séries.
     *
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

        // `poster` : URL CDN absolue (zone des visuels) ou null — le front
        // retombe alors sur son affiche générée. Absent en source `bunny`,
        // qui ne connaît que l'arborescence vidéo.
        $data = [];
        foreach ($films as $f) {
            $data[] = [
                'slug' => $f->getSlug(),
                'title' => $f->getTitle(),
                'kind' => 'film',
                'poster' => $f->getPoster(),
            ];
        }
        foreach ($series as $s) {
            $data[] = [
                'slug' => $s->getSlug(),
                'title' => $s->getTitle(),
                'kind' => 'serie',
                'poster' => $s->getPoster(),
            ];
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
     * Fiche en source `db` : cherche un film PUBLISHED portant ce slug, puis
     * une série. À slug identique, le film l'emporte.
     *
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
     * Œuvre retirée de la plateforme (WITHDRAWN) portant ce slug, pour la
     * réponse 410 de la fiche publique. À slug identique, le film l'emporte,
     * comme dans detailFromDb().
     *
     * @return array{kind: string, title: string}|null null si aucune œuvre retirée
     */
    private function findWithdrawn(string $slug): ?array
    {
        $film = $this->filmRepo->findOneBy(['slug' => $slug, 'status' => Film::STATUS_WITHDRAWN]);
        if ($film !== null) {
            return ['kind' => 'film', 'title' => $film->getTitle()];
        }
        $serie = $this->serieRepo->findOneBy(['slug' => $slug, 'status' => Serie::STATUS_WITHDRAWN]);
        if ($serie !== null) {
            return ['kind' => 'serie', 'title' => $serie->getTitle()];
        }
        return null;
    }

    /**
     * Indique si une œuvre existe (pour can-play).
     * Retourne true / false / null (null = erreur infra Bunny).
     *
     * Mêmes règles que la fiche : en source `db`, une œuvre non publiée est
     * considérée comme inexistante ; en source `bunny`, le détail complet est
     * construit (et mis en cache) par getWork().
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

    /**
     * Convertit un Film publié au format DiscoverWork : une saison unique
     * `principale` dont les épisodes sont les parties du film, plus les
     * métadonnées éditoriales, la bande-annonce et la référence studio.
     */
    private function mapFilmToDiscover(Film $film): array
    {
        // Tout est résolu depuis la base : l'import (`app:catalogue:import-bunny`)
        // enregistre le chemin Bunny de chaque partie du film dans `FilmPart`.
        // Un film ordinaire n'a qu'une partie et conserve le slug d'épisode
        // historique `principal` (liens `?ep=principal&s=principale` de la fiche).
        // Les films livrés en plusieurs morceaux (ex. `FILMS/GUCCI_BROTHERS`)
        // exposent une partie par entrée, dans l'ordre.
        $parts = $film->getParts();
        $episodes = [];

        if (count($parts) > 0) {
            $single = count($parts) === 1;
            foreach ($parts as $part) {
                $episodes[] = [
                    'slug' => $single ? 'principal' : sprintf('partie-%d', $part->getNumber()),
                    'name' => $single ? $film->getTitle() : $part->getTitle(),
                    'hlsUrl' => $this->buildHlsUrl($part->getBunnyVideoId()),
                    'mp4Url' => null,
                ];
            }
        } elseif (($bunnyPath = $film->getBunnyVideoId()) !== null && $bunnyPath !== '') {
            // Film sans FilmPart : contenu créé via le module Studio, ou ligne
            // antérieure à l'introduction des parties.
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
            'poster' => $film->getPoster(),
            // Référence studio (null en mode catalogue Bunny live, sinon dérivée de l'entité)
            // — permet à la page détail d'afficher « Publié par {studio} » avec lien vers la chaîne.
            'studio' => $this->mapStudioRef($film->getStudio()),
            // Métadonnées éditoriales de la fiche (façon cinaf.tv). Les valeurs
            // absentes sont null / [] : les œuvres importées n'ont ni année, ni
            // durée, ni genres — seul le contenu saisi via le Studio est complet.
            'synopsis' => $this->publicSynopsis($film->getSynopsis()),
            'year' => $film->getYear() ?: null,
            'duration' => $film->getDuration() ?: null,
            'genres' => $this->names($film->getGenres()),
            'countries' => $this->names($film->getCountries()),
            'directors' => $this->personNames($film->getDirectors()),
            'cast' => $this->personNames($film->getCast()),
            'trailerUrl' => $this->trailerUrl($film->getTrailerVideoId()),
            'seasons' => [[
                'slug' => 'principale',
                'name' => 'Œuvre',
                'episodes' => $episodes,
            ]],
        ];
    }

    /**
     * Convertit une Serie publiée au format DiscoverWork : saisons et épisodes
     * issus de la base, chaque épisode recevant son URL HLS et son numéro réel.
     * Slugs générés : `saison-{N}` pour une saison, `e-{NN}` pour un épisode
     * (numéros stockés en base).
     */
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
                    'number' => $this->episodeNumber($episode),
                    'duration' => $episode->getDuration() ?: null,
                    'synopsis' => $episode->getSynopsis() ?: null,
                ];
            }
            // L'import numérote dans l'ordre des dossiers Bunny, qui n'est pas
            // celui des épisodes : on ordonne sur le numéro réel.
            usort($episodes, static fn($a, $b) => $a['number'] <=> $b['number']);
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
            'poster' => $serie->getPoster(),
            // Cf. mapFilmToDiscover.
            'studio' => $this->mapStudioRef($serie->getStudio()),
            'synopsis' => $this->publicSynopsis($serie->getSynopsis()),
            'year' => $serie->getYear() ?: null,
            'nbSeasons' => count($seasons),
            'genres' => $this->names($serie->getGenres()),
            'countries' => $this->names($serie->getCountries()),
            'trailerUrl' => $this->trailerUrl($serie->getTrailerVideoId()),
            'seasons' => $seasons,
        ];
    }

    /**
     * Numéro réel de l'épisode : celui porté par le nom du dossier Bunny
     * (« …_EP_4 », « EPISODE 12 ») quand il existe, sinon le numéro en base.
     */
    private function episodeNumber(Episode $episode): int
    {
        if (preg_match('/EP(?:ISODE)?[\s_-]*(\d{1,3})\b/i', $episode->getTitle(), $m)) {
            return (int) $m[1];
        }
        return $episode->getNumber();
    }

    /** Synopsis affichable, ou null s'il s'agit du texte technique de l'import. */
    private function publicSynopsis(string $synopsis): ?string
    {
        $synopsis = trim($synopsis);
        return $synopsis === '' || $synopsis === self::IMPORT_PLACEHOLDER_SYNOPSIS ? null : $synopsis;
    }

    /**
     * Noms des genres ou des pays rattachés à une œuvre.
     *
     * @param  iterable<\App\Entity\Genre|\App\Entity\Country> $items
     * @return list<string>
     */
    private function names(iterable $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[] = $item->getName();
        }
        return $out;
    }

    /**
     * Noms complets (« prénom nom ») des réalisateurs ou acteurs d'un film.
     *
     * @param  iterable<\App\Entity\Person> $people
     * @return list<string>
     */
    private function personNames(iterable $people): array
    {
        $out = [];
        foreach ($people as $p) {
            $out[] = trim($p->getFirstName() . ' ' . $p->getLastName());
        }
        return $out;
    }

    /** La bande-annonce est un dossier Bunny Storage converti en HLS, comme les vidéos. */
    private function trailerUrl(?string $bunnyPath): ?string
    {
        return $bunnyPath !== null && $bunnyPath !== '' ? $this->buildHlsUrl($bunnyPath) : null;
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

    /**
     * Convertit un chemin Bunny Storage (`bunnyVideoId`, `trailerVideoId`,
     * `FilmPart.bunnyVideoId`) en URL de lecture HLS servie par la pull zone
     * de la zone catalogue.
     *
     * Le chemin est traité comme un DOSSIER contenant un rendu HLS : on y
     * ajoute `/master.m3u8`. Exemple : `FILMS/CLEOPATRA/Cleopatra` →
     * `https://cinaftv-movies.b-cdn.net/FILMS/CLEOPATRA/Cleopatra/master.m3u8`.
     * Aucun appel réseau : l'existence du manifeste n'est pas vérifiée.
     *
     * @return string URL absolue, ou chaîne vide si la zone catalogue n'est pas déclarée.
     */
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

    /**
     * Slug simplifié pour les identifiants de saison et d'épisode générés ici
     * (`saison-1`, `e-01`) : toute suite de caractères non alphanumériques
     * devient « - », minuscules, « item » si le résultat est vide.
     */
    private function slugify(string $name): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '');
        return trim($slug, '-') ?: 'item';
    }
}
