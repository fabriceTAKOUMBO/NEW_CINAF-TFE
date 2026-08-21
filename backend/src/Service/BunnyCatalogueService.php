<?php
namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Catalogue public exposé aux utilisateurs : parse l'arborescence d'une
 * Storage Zone Bunny (par défaut `cinaftv-movies`) et la transforme en
 * structure {Œuvre → Saison → Épisode → URL HLS}.
 *
 * Bypasse volontairement les entités Doctrine Film/Serie/Episode tant que
 * la base de données n'est pas synchronisée avec Bunny.
 *
 * Deux structures sont supportées :
 * - Œuvre / Saison / Épisode / master.m3u8 (ex: 12_CAS/CAS_1/CAS1_E01/...)
 * - Œuvre / Épisode / master.m3u8 (ex: A_bientot/BA_xxx/...)
 *
 * La détection se fait par listing : si un sous-dossier de l'œuvre contient
 * lui-même `master.m3u8`, c'est un épisode (structure flat) ; sinon c'est
 * une saison dont les sous-dossiers sont des épisodes.
 *
 * @deprecated Phase F (Agent 5) — Utiliser le catalogue DB une fois
 *             `app:catalogue:import-bunny` exécuté et `CATALOGUE_SOURCE=db`.
 *             Conservé pour rollback safe et pour la commande d'import.
 */
class BunnyCatalogueService
{
    private const CACHE_TTL = 300; // 5 minutes
    private const HLS_MANIFEST = 'master.m3u8';
    private const ORIGINAL_MP4 = 'original.mp4';
    private const QUALITY_DIRS = ['240p', '360p', '480p', '720p', '1080p'];

    // Regex pour détecter un nom de saison (CAS_1, SAISON_2, S03, S_3, SAISON3, ...).
    // Si l'œuvre contient des sous-dossiers qui matchent ce pattern, c'est une série.
    private const SEASON_PATTERN = '/^(cas|saison|s)[ _-]?\d+$/i';

    /** Dossier racine regroupant les films de la zone. */
    private const FILMS_DIR = 'FILMS';

    /**
     * Dossiers racine qui ne sont pas des œuvres : conteneur des films (traité
     * séparément), espace du module Studio, extraits, doublons et gabarits
     * techniques. La zone Bunny n'est jamais modifiée : on filtre côté app.
     */
    private const NON_WORK_DIRS = [
        self::FILMS_DIR,
        'studios',
        'teasers',
        's3',
        'mail-template',
        'mail_template',
        'notification',
    ];

    /** Bande-annonce : `BA_x`, `x_BA`, `BANDE_ANNONCE_x`, `x_TEASER`. */
    private const TRAILER_PATTERN = '/(^|[\s_-])(ba|bande[\s_-]?ann?once|teaser)([\s_-]|$)/i';

    /** Partie générique d'un film : `PART1`, `PARTIE_2`, `P3`. */
    private const PART_PATTERN = '/^(part|partie|p)[\s_-]?\d+$/i';

    /** Profondeur maximale explorée sous `FILMS/`. */
    private const MAX_FILM_DEPTH = 3;

    private readonly BunnyStorageService $storage;

    public function __construct(
        BunnyZoneRegistry $zones,
        private readonly string $catalogueZone,
        private readonly CacheInterface $cache,
    ) {
        $this->storage = $zones->get($this->catalogueZone);
    }

    /**
     * Liste les œuvres (dossiers racine) avec pagination, recherche libre et
     * filtre par type (film | serie). Le type est calculé à la volée à partir
     * de la structure des sous-dossiers de l'œuvre.
     *
     * @param 'film'|'serie'|null $kind
     * @return array{data: list<array{slug:string, title:string, kind:string}>, total:int, page:int, limit:int}
     */
    public function listWorks(?string $query = null, ?string $kind = null, int $page = 1, int $limit = 30): array
    {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));

        $works = $this->fetchRootWorks();

        if ($query !== null && $query !== '') {
            $needle = mb_strtolower($query);
            $works = array_values(array_filter(
                $works,
                static fn(array $w) => str_contains(mb_strtolower($w['title']), $needle),
            ));
        }

        if ($kind === 'film' || $kind === 'serie') {
            $works = array_values(array_filter(
                $works,
                static fn(array $w) => $w['kind'] === $kind,
            ));
        }

        $total = count($works);
        $offset = ($page - 1) * $limit;
        $page_data = array_slice($works, $offset, $limit);

        return [
            'data' => array_map(
                static fn($w) => ['slug' => $w['slug'], 'title' => $w['title'], 'kind' => $w['kind']],
                $page_data,
            ),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * Drill-down sur une œuvre : retourne ses saisons et épisodes avec URL HLS.
     *
     * @return array{slug:string, title:string, seasons: list<array{slug:string, name:string, episodes: list<array{slug:string, name:string, hlsUrl:string, mp4Url:?string}>}>}|null
     */
    public function getWork(string $slug): ?array
    {
        $work = $this->findWorkBySlug($slug);
        if ($work === null) {
            return null;
        }

        return $this->cache->get(
            "bunny.catalogue.work.$slug",
            function (ItemInterface $item) use ($work): array {
                $item->expiresAfter(self::CACHE_TTL);

                $level1 = $this->storage->listContents($work['path'], false);

                $seasons = [];
                $rootEpisodes = [];

                foreach ($level1['directories'] as $dir1) {
                    $level2 = $this->storage->listContents($dir1['path'], false);

                    if ($this->listingIsEpisode($level2)) {
                        // dir1 contient master.m3u8 (ou original.mp4 ou des qualités)
                        // → c'est un épisode (œuvre flat)
                        $rootEpisodes[] = $this->buildEpisode($dir1['path'], $dir1['name'], $level2);
                        continue;
                    }

                    // dir1 = saison ; ses sous-dossiers = épisodes (on suppose la convention)
                    $episodes = [];
                    foreach ($level2['directories'] as $dir2) {
                        $episodes[] = $this->buildEpisode($dir2['path'], $dir2['name'], null);
                    }
                    usort($episodes, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

                    $seasons[] = [
                        'slug' => $this->slugify($dir1['name']),
                        'name' => $dir1['name'],
                        'episodes' => $episodes,
                    ];
                }

                usort($seasons, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

                if (!empty($rootEpisodes)) {
                    usort($rootEpisodes, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
                    array_unshift($seasons, [
                        'slug' => 'principale',
                        'name' => 'Œuvre',
                        'episodes' => $rootEpisodes,
                    ]);
                }

                return [
                    'slug' => $work['slug'],
                    'title' => $work['title'],
                    'kind' => $work['kind'],
                    'seasons' => $seasons,
                ];
            },
        );
    }

    /**
     * Liste TOUTES les œuvres du catalogue Bunny avec leur structure complète —
     * utilisée par la commande d'import DB. Pas de cache : on veut une vue
     * cohérente au moment de l'import.
     *
     * Classification, déduite de l'arborescence réelle de la zone (jamais
     * modifiée par l'application — tout est adapté ici) :
     *  - `FILMS/` contient les films : chacun de ses sous-dossiers est un film,
     *    sauf les vraies catégories (enfants = titres distincts, ex.
     *    `FILMS_EN_ANGLAIS`) et les bandes-annonces orphelines (`Raube_BA`).
     *  - Tout autre dossier racine est une série, hors {@see self::NON_WORK_DIRS}.
     *  - Sous une série, `CAS_x` / `SAISON_x` / `S_x` est une saison ; les autres
     *    sous-dossiers sont des épisodes « à plat » (ex. `MAD_SAL_E01`).
     *  - Les dossiers de bande-annonce ne sont jamais comptés comme épisode ni
     *    comme partie ; ils alimentent `trailerPath`.
     *  - Un ré-encodage doublon `X_F` est ignoré quand `X` existe.
     *
     * @return list<array{
     *   slug:string,
     *   title:string,
     *   kind:'film'|'serie',
     *   path:string,
     *   trailerPath:?string,
     *   parts: list<array{name:string, path:string}>,
     *   seasons: list<array{name:string, path:string, episodes: list<array{name:string, path:string}>}>
     * }>
     */
    public function listAllWorks(): array
    {
        $works = array_merge($this->collectFilms(), $this->collectSeries());
        usort($works, fn($a, $b) => strcasecmp($a['title'], $b['title']));

        // Slugs uniques, affectés après tri pour rester déterministes.
        $usedSlugs = [];
        foreach ($works as $i => $work) {
            $base = $this->slugify($work['title']);
            $unique = $base;
            $n = 2;
            while (isset($usedSlugs[$unique])) {
                $unique = "$base-$n";
                $n++;
            }
            $usedSlugs[$unique] = true;
            $works[$i]['slug'] = $unique;
        }

        return $works;
    }

    /**
     * Résout les films contenus dans `FILMS/`.
     *
     * @return list<array<string,mixed>>
     */
    private function collectFilms(): array
    {
        try {
            $listing = $this->storage->listContents(self::FILMS_DIR, false);
        } catch (\Throwable) {
            return [];
        }

        $films = [];
        foreach ($listing['directories'] as $dir) {
            $node = $this->resolveFilmNode($dir['path'], $dir['name'], 1);
            foreach ($this->nodeToFilms($node) as $film) {
                $films[] = $film;
            }
        }
        return $films;
    }

    /**
     * Classe un dossier de la branche `FILMS/` : vidéo, film, catégorie ou vide.
     *
     * @return array{type:'video'|'film'|'category'|'empty', name?:string, path?:string, videos?:list<array{name:string,path:string}>, children?:list<array<string,mixed>>}
     */
    private function resolveFilmNode(string $path, string $name, int $depth): array
    {
        try {
            $listing = $this->storage->listContents($path, false);
        } catch (\Throwable) {
            return ['type' => 'empty'];
        }

        // Le dossier porte lui-même la vidéo (master.m3u8 / original.mp4 / rendus).
        if ($this->listingIsEpisode($listing)) {
            return ['type' => 'video', 'name' => $name, 'path' => $path];
        }

        if ($depth >= self::MAX_FILM_DEPTH || empty($listing['directories'])) {
            return ['type' => 'empty'];
        }

        $children = [];
        foreach ($listing['directories'] as $dir) {
            $children[] = $this->resolveFilmNode($dir['path'], $dir['name'], $depth + 1);
        }

        $nested = array_filter(
            $children,
            static fn(array $c) => $c['type'] === 'film' || $c['type'] === 'category',
        );

        // Aucun enfant n'est lui-même un film : ce dossier EST le film, ses
        // sous-dossiers vidéo sont ses parties / sa bande-annonce.
        if ($nested === []) {
            return [
                'type' => 'film',
                'name' => $name,
                'path' => $path,
                'videos' => array_values(array_map(
                    static fn(array $c) => ['name' => $c['name'], 'path' => $c['path']],
                    array_filter($children, static fn(array $c) => $c['type'] === 'video'),
                )),
            ];
        }

        // Des enfants sont eux-mêmes des films : soit ce sont les parties d'un
        // même film (nom générique `PARTn` ou dérivé du parent), soit une
        // catégorie regroupant des titres distincts.
        foreach ($listing['directories'] as $dir) {
            if ($this->isTrailerName($dir['name'])) {
                continue; // une bande-annonce ne tranche pas partie vs catégorie
            }
            if ($this->looksLikePart($dir['name'], $name)) {
                return [
                    'type' => 'film',
                    'name' => $name,
                    'path' => $path,
                    'videos' => $this->flattenVideos($children),
                ];
            }
        }

        return ['type' => 'category', 'name' => $name, 'path' => $path, 'children' => $children];
    }

    /**
     * Transforme un nœud résolu en 0, 1 ou N œuvres de type film.
     *
     * @param  array<string,mixed>      $node
     * @return list<array<string,mixed>>
     */
    private function nodeToFilms(array $node): array
    {
        if ($node['type'] === 'video') {
            // Vidéo isolée directement sous `FILMS/` : bande-annonce orpheline
            // → ignorée ; sinon film à fichier unique.
            if ($this->isTrailerName($node['name'])) {
                return [];
            }
            return [$this->makeFilm(
                $node['name'],
                $node['path'],
                [['name' => $node['name'], 'path' => $node['path']]],
            )];
        }

        if ($node['type'] === 'film') {
            return [$this->makeFilm($node['name'], $node['path'], $node['videos'])];
        }

        if ($node['type'] === 'category') {
            $films = [];
            foreach ($node['children'] as $child) {
                foreach ($this->nodeToFilms($child) as $film) {
                    $films[] = $film;
                }
            }
            return $films;
        }

        return [];
    }

    /**
     * @param  list<array{name:string, path:string}> $videos
     * @return array<string,mixed>
     */
    private function makeFilm(string $title, string $path, array $videos): array
    {
        $split = $this->splitVideos($videos);

        return [
            'slug' => '', // affecté par listAllWorks() après tri
            'title' => $title,
            'kind' => 'film',
            'path' => $path,
            'trailerPath' => $split['trailer']['path'] ?? null,
            'parts' => $split['parts'],
            'seasons' => [],
        ];
    }

    /**
     * Aplatit récursivement toutes les vidéos trouvées sous une liste de nœuds.
     *
     * @param  list<array<string,mixed>>             $nodes
     * @return list<array{name:string, path:string}>
     */
    private function flattenVideos(array $nodes): array
    {
        $videos = [];
        foreach ($nodes as $node) {
            if ($node['type'] === 'video') {
                $videos[] = ['name' => $node['name'], 'path' => $node['path']];
            } elseif ($node['type'] === 'film') {
                foreach ($node['videos'] as $video) {
                    $videos[] = $video;
                }
            } elseif ($node['type'] === 'category') {
                foreach ($this->flattenVideos($node['children']) as $video) {
                    $videos[] = $video;
                }
            }
        }
        return $videos;
    }

    /**
     * Sépare bande-annonce et parties jouables, en écartant les ré-encodages
     * doublons `X_F` lorsque `X` existe (fichiers strictement identiques
     * constatés sur la zone, ex. `Cleopatra` / `Cleopatra_F`).
     *
     * @param  list<array{name:string, path:string}> $videos
     * @return array{parts: list<array{name:string, path:string}>, trailer: ?array{name:string, path:string}}
     */
    private function splitVideos(array $videos): array
    {
        $known = [];
        foreach ($videos as $video) {
            $known[$this->normalizeName($video['name'])] = true;
        }

        $parts = [];
        $trailer = null;
        foreach ($videos as $video) {
            $normalized = $this->normalizeName($video['name']);
            if (str_ends_with($normalized, 'f') && isset($known[substr($normalized, 0, -1)])) {
                continue;
            }
            if ($this->isTrailerName($video['name'])) {
                $trailer ??= $video;
                continue;
            }
            $parts[] = $video;
        }

        usort($parts, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
        return ['parts' => $parts, 'trailer' => $trailer];
    }

    /**
     * Résout les séries : tout dossier racine hors {@see self::NON_WORK_DIRS}.
     * Une œuvre sans aucun épisode exploitable est ignorée.
     *
     * @return list<array<string,mixed>>
     */
    private function collectSeries(): array
    {
        try {
            $listing = $this->storage->listContents('', false);
        } catch (\Throwable) {
            return [];
        }

        $series = [];
        foreach ($listing['directories'] as $dir) {
            if (in_array($dir['name'], self::NON_WORK_DIRS, true)) {
                continue;
            }

            $structure = $this->buildSeasons($dir['path']);
            if ($structure['seasons'] === []) {
                continue;
            }

            $series[] = [
                'slug' => '', // affecté par listAllWorks() après tri
                'title' => $dir['name'],
                'kind' => 'serie',
                'path' => $dir['path'],
                'trailerPath' => $structure['trailerPath'],
                'parts' => [],
                'seasons' => $structure['seasons'],
            ];
        }
        return $series;
    }

    /**
     * Construit les saisons d'une série. Un sous-dossier `CAS_x` / `SAISON_x` /
     * `S_x` est une saison dont les enfants sont les épisodes ; tout autre
     * sous-dossier est un épisode « à plat » regroupé dans une saison unique.
     *
     * La détection est faite sur le NOM, sans appel réseau supplémentaire par
     * épisode : les séries plates comptent jusqu'à ~90 épisodes.
     *
     * @return array{seasons: list<array{name:string, path:string, episodes: list<array{name:string, path:string}>}>, trailerPath: ?string}
     */
    private function buildSeasons(string $workPath): array
    {
        try {
            $level1 = $this->storage->listContents($workPath, false);
        } catch (\Throwable) {
            return ['seasons' => [], 'trailerPath' => null];
        }

        $seasons = [];
        $flatEpisodes = [];
        $trailerPath = null;

        foreach ($level1['directories'] as $dir1) {
            if ($this->isTrailerName($dir1['name'])) {
                $trailerPath ??= $dir1['path'];
                continue;
            }

            if (preg_match(self::SEASON_PATTERN, $dir1['name']) !== 1) {
                $flatEpisodes[] = ['name' => $dir1['name'], 'path' => $dir1['path']];
                continue;
            }

            try {
                $level2 = $this->storage->listContents($dir1['path'], false);
            } catch (\Throwable) {
                continue;
            }

            $episodes = [];
            foreach ($level2['directories'] as $dir2) {
                if ($this->isTrailerName($dir2['name'])) {
                    $trailerPath ??= $dir2['path'];
                    continue;
                }
                $episodes[] = ['name' => $dir2['name'], 'path' => $dir2['path']];
            }
            usort($episodes, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

            if ($episodes !== []) {
                $seasons[] = [
                    'name' => $dir1['name'],
                    'path' => $dir1['path'],
                    'episodes' => $episodes,
                ];
            }
        }

        usort($seasons, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

        if ($flatEpisodes !== []) {
            usort($flatEpisodes, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
            array_unshift($seasons, [
                'name' => $seasons === [] ? 'Saison 1' : 'Épisodes hors saison',
                'path' => $workPath,
                'episodes' => $flatEpisodes,
            ]);
        }

        return ['seasons' => $seasons, 'trailerPath' => $trailerPath];
    }

    private function isTrailerName(string $name): bool
    {
        return preg_match(self::TRAILER_PATTERN, $name) === 1;
    }

    /**
     * Un sous-dossier est une partie du film parent s'il porte un nom générique
     * (`PART1`, `PARTIE_2`…) ou s'il dérive du nom du parent (`ALINE_1` sous
     * `ALINE`). Sinon le parent est une catégorie regroupant des titres
     * distincts (`FILMS_EN_ANGLAIS` → `BROKEN`, `EDIMA`…).
     */
    private function looksLikePart(string $child, string $parent): bool
    {
        if (preg_match(self::PART_PATTERN, $child) === 1) {
            return true;
        }

        $c = $this->normalizeName($child);
        $p = $this->normalizeName($parent);
        if ($c === '' || $p === '') {
            return false;
        }

        $len = min(8, strlen($c), strlen($p));
        return substr($c, 0, $len) === substr($p, 0, $len);
    }

    /** Réduit un nom de dossier à ses caractères alphanumériques minuscules. */
    private function normalizeName(string $name): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $name) ?? '');
    }

    /**
     * @return list<array{slug:string, title:string, path:string, kind:string}>
     */
    private function fetchRootWorks(): array
    {
        return $this->cache->get(
            'bunny.catalogue.root',
            function (ItemInterface $item): array {
                $item->expiresAfter(self::CACHE_TTL);

                $listing = $this->storage->listContents('', false);
                $works = [];
                $usedSlugs = [];

                foreach ($listing['directories'] as $dir) {
                    $slug = $this->slugify($dir['name']);
                    $unique = $slug;
                    $i = 2;
                    while (isset($usedSlugs[$unique])) {
                        $unique = "$slug-$i";
                        $i++;
                    }
                    $usedSlugs[$unique] = true;

                    $works[] = [
                        'slug' => $unique,
                        'title' => $dir['name'],
                        'path' => $dir['path'],
                        'kind' => $this->detectKind($dir['path']),
                    ];
                }

                usort($works, fn($a, $b) => strcasecmp($a['title'], $b['title']));
                return $works;
            },
        );
    }

    /**
     * Détermine le type (film ou serie) en regardant les sous-dossiers de niveau 1
     * de l'œuvre. Si au moins un nom matche `CAS_X / SAISON_X / S_X` → série.
     * Sinon → film (œuvre flat type teasers ou film 1 seul).
     */
    private function detectKind(string $workPath): string
    {
        try {
            $level1 = $this->storage->listContents($workPath, false);
        } catch (\Throwable) {
            return 'film';
        }

        foreach ($level1['directories'] as $dir) {
            if (preg_match(self::SEASON_PATTERN, $dir['name']) === 1) {
                return 'serie';
            }
        }
        return 'film';
    }

    private function findWorkBySlug(string $slug): ?array
    {
        foreach ($this->fetchRootWorks() as $w) {
            if ($w['slug'] === $slug) {
                return $w;
            }
        }
        return null;
    }

    /**
     * @param array{files: list<array{name:string}>}|null $listing
     *        Si null, on suppose que master.m3u8 ET original.mp4 existent (convention saison).
     */
    private function buildEpisode(string $path, string $name, ?array $listing): array
    {
        $hasHls = $listing === null || $this->listingHasFile($listing, self::HLS_MANIFEST);
        $hasMp4 = $listing === null || $this->listingHasFile($listing, self::ORIGINAL_MP4);

        return [
            'slug' => $this->slugify($name),
            'name' => $name,
            'hlsUrl' => $hasHls ? $this->storage->getPublicUrl("$path/" . self::HLS_MANIFEST) : null,
            'mp4Url' => $hasMp4 ? $this->storage->getPublicUrl("$path/" . self::ORIGINAL_MP4) : null,
        ];
    }

    /**
     * Détecte si un listing correspond à un dossier d'épisode :
     * - contient master.m3u8 (HLS prêt), OU
     * - contient original.mp4 (MP4 brut), OU
     * - contient au moins 2 sous-dossiers de qualité (240p, 360p, 480p, 720p, 1080p) — cas où le
     *   manifeste n'a pas été (re-)généré mais les rendus encodés existent.
     *
     * @param array{files: list<array{name:string}>, directories: list<array{name:string}>} $listing
     */
    private function listingIsEpisode(array $listing): bool
    {
        if ($this->listingHasFile($listing, self::HLS_MANIFEST)) {
            return true;
        }
        if ($this->listingHasFile($listing, self::ORIGINAL_MP4)) {
            return true;
        }
        $qualityCount = 0;
        foreach ($listing['directories'] as $d) {
            if (in_array($d['name'], self::QUALITY_DIRS, true)) {
                $qualityCount++;
            }
        }
        return $qualityCount >= 2;
    }

    /**
     * @param array{files: list<array{name:string}>} $listing
     */
    private function listingHasFile(array $listing, string $name): bool
    {
        foreach ($listing['files'] as $f) {
            if ($f['name'] === $name) {
                return true;
            }
        }
        return false;
    }

    /**
     * Slugifie un nom de dossier Bunny en URL-safe ASCII.
     */
    private function slugify(string $name): string
    {
        $slug = $name;
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
            if ($converted !== false) {
                $slug = $converted;
            }
        }
        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        return trim($slug, '-') ?: 'item';
    }
}
