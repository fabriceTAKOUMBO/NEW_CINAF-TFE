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
 * une saison dont les sous-dossiers sont des épisodes. (Un dossier est aussi
 * reconnu comme épisode s'il contient `original.mp4` ou au moins deux
 * dossiers de rendus `240p`…`1080p` : voir listingIsEpisode().)
 *
 * Deux usages, avec deux classifications différentes :
 *  - Catalogue public « Découvrir » quand `CATALOGUE_SOURCE=bunny` :
 *    listWorks() et getWork(), appelés par CatalogueDiscoverController.
 *    Classification historique (un dossier racine = une œuvre, série si un
 *    sous-dossier ressemble à une saison), résultats mis en cache 5 min
 *    dans le pool `cache.app`.
 *  - Import en base (`app:catalogue:import-bunny`) : listAllWorks(), sans
 *    cache, qui distingue les films rangés sous `FILMS/` des séries rangées
 *    à la racine, et repère parties, bandes-annonces et doublons.
 *
 * Le service ne fait que LIRE la zone : il ne crée, ne déplace ni ne supprime
 * aucun fichier Bunny.
 *
 * @deprecated Phase F (Agent 5) — Utiliser le catalogue DB une fois
 *             `app:catalogue:import-bunny` exécuté et `CATALOGUE_SOURCE=db`.
 *             Conservé pour rollback safe et pour la commande d'import.
 */
class BunnyCatalogueService
{
    private const CACHE_TTL = 300; // 5 minutes
    /** Manifeste HLS maître d'une vidéo encodée : l'URL de lecture pointe sur ce fichier. */
    private const HLS_MANIFEST = 'master.m3u8';
    /** Fichier MP4 d'origine, parfois présent à côté des rendus HLS (exposé en `mp4Url`). */
    private const ORIGINAL_MP4 = 'original.mp4';
    /** Noms des sous-dossiers de rendus HLS, un par définition. */
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

    /**
     * Bande-annonce : `BA_x`, `x_BA`, `BANDE_ANNONCE_x`, `x_TEASER`.
     *
     * Le mot-clé doit être isolé (début ou fin du nom, espace, `_` ou `-`) :
     * `BAOBAB` ou `teasers` ne correspondent pas. `ann?once` accepte aussi
     * l'orthographe `BANDE_ANONCE` relevée sur la zone.
     */
    private const TRAILER_PATTERN = '/(^|[\s_-])(ba|bande[\s_-]?ann?once|teaser)([\s_-]|$)/i';

    /** Partie générique d'un film : `PART1`, `PARTIE_2`, `P3`. */
    private const PART_PATTERN = '/^(part|partie|p)[\s_-]?\d+$/i';

    /** Profondeur maximale explorée sous `FILMS/`. */
    private const MAX_FILM_DEPTH = 3;

    private readonly BunnyStorageService $storage;

    /**
     * Résout une fois pour toutes le client Bunny de la zone catalogue.
     *
     * @param string         $catalogueZone Zone lue (paramètre `app.bunny.catalogue_zone`,
     *                                      variable d'environnement `BUNNY_CATALOGUE_ZONE`).
     * @param CacheInterface $cache         Pool `cache.app` (voir `config/services.yaml`).
     *
     * @throws \InvalidArgumentException si la zone n'est pas déclarée dans `app.bunny.zones`.
     */
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
     * Source `bunny` de `GET /api/catalogue/discover`. Recherche, filtre et
     * pagination se font en mémoire sur la liste mise en cache par
     * fetchRootWorks() : aucun appel Bunny tant que le cache est chaud.
     *
     * Attention : classification historique. Tous les dossiers racine sont
     * listés, y compris `FILMS/` (vu comme une seule œuvre) et les dossiers
     * techniques de {@see self::NON_WORK_DIRS}, que seul listAllWorks() écarte.
     *
     * @param string|null         $query Texte recherché dans le titre (insensible à la casse).
     * @param 'film'|'serie'|null $kind
     * @param int                 $page  Page demandée (ramenée à 1 au minimum).
     * @param int                 $limit Taille de page (bornée entre 1 et 100).
     *
     * @throws \RuntimeException si le cache est vide et que Bunny ne répond pas
     *                           (le contrôleur la traduit en 502).
     *
     * @return array{data: list<array{slug:string, title:string, kind:string}>, total:int, page:int, limit:int}
     */
    public function listWorks(?string $query = null, ?string $kind = null, int $page = 1, int $limit = 30): array
    {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));

        $works = $this->fetchRootWorks();

        // Filtres appliqués avant la pagination : `total` compte les œuvres filtrées.
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
     * Source `bunny` de `GET /api/catalogue/discover/{slug}` et de la
     * vérification d'existence de `can-play`. Le détail est mis en cache 5 min
     * par œuvre (clé `bunny.catalogue.work.{slug}`) ; au premier accès, il
     * coûte un listing de l'œuvre plus un listing par sous-dossier.
     *
     * @throws \RuntimeException si Bunny ne répond pas pendant la construction du détail.
     *
     * @return array{slug:string, title:string, seasons: list<array{slug:string, name:string, episodes: list<array{slug:string, name:string, hlsUrl:string, mp4Url:?string}>}>}|null
     */
    public function getWork(string $slug): ?array
    {
        // Le slug reçu de l'URL doit d'abord exister parmi les œuvres connues :
        // la clé de cache ci-dessous n'est donc construite qu'avec un slug valide.
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

                // Chaque sous-dossier de l'œuvre est listé pour savoir s'il porte
                // lui-même une vidéo (épisode) ou s'il regroupe des épisodes (saison).
                foreach ($level1['directories'] as $dir1) {
                    $level2 = $this->storage->listContents($dir1['path'], false);

                    if ($this->listingIsEpisode($level2)) {
                        // dir1 contient master.m3u8 (ou original.mp4 ou des qualités)
                        // → c'est un épisode (œuvre flat)
                        $rootEpisodes[] = $this->buildEpisode($dir1['path'], $dir1['name'], $level2);
                        continue;
                    }

                    // dir1 = saison ; ses sous-dossiers = épisodes (on suppose la convention)
                    // Les dossiers d'épisodes ne sont PAS listés (listing null) : un
                    // appel Bunny par saison au lieu d'un par épisode, mais les URL
                    // HLS/MP4 sont supposées exister sans vérification.
                    $episodes = [];
                    foreach ($level2['directories'] as $dir2) {
                        $episodes[] = $this->buildEpisode($dir2['path'], $dir2['name'], null);
                    }
                    // Tri naturel : « E2 » avant « E10 ».
                    usort($episodes, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

                    $seasons[] = [
                        'slug' => $this->slugify($dir1['name']),
                        'name' => $dir1['name'],
                        'episodes' => $episodes,
                    ];
                }

                usort($seasons, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

                // Les épisodes « à plat » sont regroupés dans une pseudo-saison
                // placée en tête. Slug `principale` / nom « Œuvre » : les mêmes
                // que la source db pour un film ; le frontend traite ce slug à
                // part (la page de lecture n'affiche alors aucun nom de saison).
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
     * Aucune exception n'est propagée : un dossier dont le listing Bunny échoue
     * est simplement absent du résultat (et toute la branche `FILMS/` ou toute
     * la racine si c'est leur listing qui échoue). Une panne Bunny peut donc
     * produire une liste partielle, voire vide.
     *
     * Coût : un appel Bunny par dossier exploré (films jusqu'à
     * {@see self::MAX_FILM_DEPTH} niveaux sous `FILMS/` ; pour une série, son
     * dossier puis chacune de ses saisons).
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
        // En cas de collision, le premier titre (ordre alphabétique) garde le
        // slug de base, les suivants reçoivent `-2`, `-3`…
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
     * Chaque sous-dossier direct est classé récursivement par resolveFilmNode()
     * puis converti en zéro, un ou plusieurs films par nodeToFilms() (une
     * catégorie en produit plusieurs, une bande-annonce orpheline aucun).
     * Renvoie les films au format de listAllWorks(), slug encore vide ; liste
     * vide si `FILMS/` ne peut pas être listé.
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
        // Les fichiers posés directement dans `FILMS/` sont ignorés : seuls les dossiers comptent.
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
     *  - `video`    : le dossier contient lui-même une vidéo lisible ;
     *  - `film`     : ses sous-dossiers vidéo sont les parties et/ou la
     *                 bande-annonce d'un même film (`videos`) ;
     *  - `category` : il regroupe des titres distincts (`children`, chacun
     *                 converti ensuite en film) ;
     *  - `empty`    : rien d'exploitable (listing en erreur, aucun
     *                 sous-dossier, ou profondeur maximale atteinte).
     *
     * @param int $depth Profondeur sous `FILMS/` (1 = sous-dossier direct), plafonnée par MAX_FILM_DEPTH.
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

        // À la profondeur maximale, seul un dossier vidéo (test ci-dessus) est
        // retenu : on ne descend pas plus bas.
        if ($depth >= self::MAX_FILM_DEPTH || empty($listing['directories'])) {
            return ['type' => 'empty'];
        }

        // Classement récursif des enfants (un appel Bunny par sous-dossier).
        $children = [];
        foreach ($listing['directories'] as $dir) {
            $children[] = $this->resolveFilmNode($dir['path'], $dir['name'], $depth + 1);
        }

        // Enfants qui sont eux-mêmes des films ou des catégories (pas de simples vidéos).
        $nested = array_filter(
            $children,
            static fn(array $c) => $c['type'] === 'film' || $c['type'] === 'category',
        );

        // Aucun enfant n'est lui-même un film : ce dossier EST le film, ses
        // sous-dossiers vidéo sont ses parties / sa bande-annonce.
        // Les enfants `empty` sont ignorés : si aucun enfant n'est une vidéo, on
        // obtient un film sans aucune vidéo, que la commande d'import signale
        // au lieu de le créer.
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
        // Il suffit d'UN sous-dossier (hors bande-annonce) au nom de partie pour
        // que tout le dossier soit un seul film : toutes les vidéos trouvées
        // dessous, même imbriquées, deviennent ses parties.
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
     * `video` → 1 film à partie unique (0 si c'est une bande-annonce),
     * `film` → 1 film, `category` → les films de chaque enfant (récursif),
     * `empty` → aucun.
     *
     * @param  array<string,mixed>      $node
     * @return list<array<string,mixed>>
     */
    private function nodeToFilms(array $node): array
    {
        if ($node['type'] === 'video') {
            // Vidéo isolée directement sous `FILMS/` : bande-annonce orpheline
            // → ignorée ; sinon film à fichier unique.
            // (Même règle pour une vidéo posée directement dans une catégorie,
            // cette méthode étant rappelée sur chaque enfant d'une catégorie.)
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
     * Assemble une œuvre de type film au format de listAllWorks() : les
     * vidéos sont réparties entre bande-annonce et parties par splitVideos(),
     * sans aucune saison.
     *
     * @param string $title Titre = nom du dossier du film.
     * @param string $path  Dossier Bunny du film (clé d'idempotence `bunnyFolder` à l'import).
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
            // Doublon : nom normalisé terminé par « f » dont la version sans ce
            // « f » existe aussi (`cleopatraf` quand `cleopatra` est présent).
            if (str_ends_with($normalized, 'f') && isset($known[substr($normalized, 0, -1)])) {
                continue;
            }
            // Seule la première bande-annonce rencontrée est conservée.
            if ($this->isTrailerName($video['name'])) {
                $trailer ??= $video;
                continue;
            }
            $parts[] = $video;
        }

        // Tri naturel : PART2 avant PART10 ; l'ordre donne la numérotation des FilmPart à l'import.
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
            // Comparaison exacte, sensible à la casse, avec les noms réels de la zone.
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
            // Bande-annonce : la première trouvée devient `trailerPath`, jamais un épisode.
            if ($this->isTrailerName($dir1['name'])) {
                $trailerPath ??= $dir1['path'];
                continue;
            }

            // Nom qui n'est pas celui d'une saison → épisode « à plat », sans listing.
            if (preg_match(self::SEASON_PATTERN, $dir1['name']) !== 1) {
                $flatEpisodes[] = ['name' => $dir1['name'], 'path' => $dir1['path']];
                continue;
            }

            // Saison : ses sous-dossiers sont les épisodes. Listing en erreur →
            // saison ignorée, le reste de la série est conservé.
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

            // Une saison vide n'est pas créée.
            if ($episodes !== []) {
                $seasons[] = [
                    'name' => $dir1['name'],
                    'path' => $dir1['path'],
                    'episodes' => $episodes,
                ];
            }
        }

        usort($seasons, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

        // Épisodes à plat regroupés dans une saison placée en tête, qui pointe
        // sur le dossier de l'œuvre : « Saison 1 » si la série n'a pas d'autre
        // saison, « Épisodes hors saison » sinon.
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

    /** Vrai si le nom de dossier désigne une bande-annonce ({@see self::TRAILER_PATTERN}). */
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

        // Préfixe commun comparé sur 8 caractères normalisés au plus (moins si
        // l'un des deux noms est plus court) : `aline1` / `aline` → « aline ».
        $len = min(8, strlen($c), strlen($p));
        return substr($c, 0, $len) === substr($p, 0, $len);
    }

    /**
     * Réduit un nom de dossier à ses caractères alphanumériques minuscules.
     * Séparateurs et caractères accentués sont supprimés (pas translittérés).
     */
    private function normalizeName(string $name): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $name) ?? '');
    }

    /**
     * Liste des œuvres du catalogue public en source bunny : un dossier racine
     * de la zone = une œuvre, avec slug unique et type (film/serie).
     *
     * Mise en cache 5 min (clé `bunny.catalogue.root`). Au rafraîchissement :
     * un listing de la racine puis un listing par dossier (detectKind()).
     * Si le listing de la racine échoue, l'exception remonte et rien n'est
     * mis en cache.
     *
     * @throws \RuntimeException si la racine de la zone ne peut pas être listée.
     *
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

                // Pas de filtre NON_WORK_DIRS ici (classification historique) :
                // tous les dossiers racine deviennent des œuvres.
                foreach ($listing['directories'] as $dir) {
                    // Slug unique : suffixe `-2`, `-3`… en cas de collision.
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
            // Un dossier illisible ne doit pas faire échouer toute la liste :
            // il est classé « film » par défaut.
            return 'film';
        }

        foreach ($level1['directories'] as $dir) {
            if (preg_match(self::SEASON_PATTERN, $dir['name']) === 1) {
                return 'serie';
            }
        }
        return 'film';
    }

    /**
     * Retrouve une œuvre racine par son slug, dans la liste mise en cache.
     *
     * @throws \RuntimeException si la liste doit être reconstruite et que Bunny ne répond pas.
     *
     * @return array{slug:string, title:string, path:string, kind:string}|null null si aucun dossier ne porte ce slug.
     */
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
     * Construit l'entrée « épisode » du contrat Discover : slug, nom, URL HLS
     * (`master.m3u8`) et URL MP4 (`original.mp4`), toutes deux servies par la
     * pull zone ; une URL vaut null quand le listing fourni ne contient pas le
     * fichier correspondant.
     *
     * @param array{files: list<array{name:string}>}|null $listing
     *        Si null, on suppose que master.m3u8 ET original.mp4 existent (convention saison).
     *
     * @return array{slug:string, name:string, hlsUrl:?string, mp4Url:?string}
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
     * Vrai si le listing contient un fichier portant exactement ce nom (casse comprise).
     *
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
     *
     * Accents translittérés par iconv quand c'est possible (sinon le nom brut
     * est conservé), minuscules, toute suite de caractères non alphanumériques
     * remplacée par « - » ; « item » si rien ne subsiste. Le résultat respecte
     * donc la contrainte `[a-z0-9-]+` des routes `/api/catalogue/discover/{slug}`.
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
