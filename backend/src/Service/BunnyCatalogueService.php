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
     * Liste TOUTES les œuvres du catalogue Bunny avec leur structure complète
     * (saisons, épisodes, paths) — utilisée par la commande d'import DB.
     *
     * Contrairement à `listWorks()` qui pagine et filtre pour l'affichage public,
     * cette méthode parcourt l'intégralité du catalogue. Pas de cache pour garantir
     * une vue cohérente lors de l'import.
     *
     * @return list<array{
     *   slug:string,
     *   title:string,
     *   kind:string,
     *   path:string,
     *   seasons: list<array{
     *     name:string,
     *     path:string,
     *     episodes: list<array{name:string, path:string}>
     *   }>
     * }>
     */
    public function listAllWorks(): array
    {
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

            $structure = $this->buildWorkStructure($dir['path']);

            $works[] = [
                'slug' => $unique,
                'title' => $dir['name'],
                'path' => $dir['path'],
                'kind' => $structure['kind'],
                'seasons' => $structure['seasons'],
            ];
        }

        usort($works, fn($a, $b) => strcasecmp($a['title'], $b['title']));
        return $works;
    }

    /**
     * Construit la structure {kind, seasons[]} d'une œuvre en parcourant
     * son arborescence de niveau 1 et 2.
     *
     * @return array{kind:string, seasons: list<array{name:string, path:string, episodes: list<array{name:string, path:string}>}>}
     */
    private function buildWorkStructure(string $workPath): array
    {
        try {
            $level1 = $this->storage->listContents($workPath, false);
        } catch (\Throwable) {
            return ['kind' => 'film', 'seasons' => []];
        }

        $seasons = [];
        $rootEpisodes = [];
        $isSerie = false;

        foreach ($level1['directories'] as $dir1) {
            try {
                $level2 = $this->storage->listContents($dir1['path'], false);
            } catch (\Throwable) {
                continue;
            }

            if ($this->listingIsEpisode($level2)) {
                $rootEpisodes[] = ['name' => $dir1['name'], 'path' => $dir1['path']];
                continue;
            }

            // dir1 = saison ; ses sous-dossiers = épisodes.
            if (preg_match(self::SEASON_PATTERN, $dir1['name']) === 1) {
                $isSerie = true;
            }

            $episodes = [];
            foreach ($level2['directories'] as $dir2) {
                $episodes[] = ['name' => $dir2['name'], 'path' => $dir2['path']];
            }
            usort($episodes, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

            $seasons[] = [
                'name' => $dir1['name'],
                'path' => $dir1['path'],
                'episodes' => $episodes,
            ];
        }

        usort($seasons, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

        if (!empty($rootEpisodes)) {
            usort($rootEpisodes, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
            // Si on a UNIQUEMENT des épisodes flat → film (1 seul "épisode") ou
            // série flat (plusieurs épisodes sans saisons). Convention : film.
            if (empty($seasons) && !$isSerie) {
                return [
                    'kind' => 'film',
                    'seasons' => [[
                        'name' => 'Œuvre',
                        'path' => $workPath,
                        'episodes' => $rootEpisodes,
                    ]],
                ];
            }
            // Cas mixte : série avec teaser à la racine — on prepend.
            array_unshift($seasons, [
                'name' => 'Œuvre',
                'path' => $workPath,
                'episodes' => $rootEpisodes,
            ]);
            $isSerie = true;
        }

        return [
            'kind' => $isSerie ? 'serie' : 'film',
            'seasons' => $seasons,
        ];
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
