<?php
namespace App\Command;

use App\Entity\Film;
use App\Entity\Serie;
use App\Repository\FilmRepository;
use App\Repository\SerieRepository;
use App\Service\BunnyStorageService;
use App\Service\BunnyZoneRegistry;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renseigne `Film.poster` / `Serie.poster` depuis la zone Bunny des visuels.
 *
 * Contexte : les visuels vivent dans une zone dédiée (`cinaf-engine-zone`),
 * indexée par les identifiants internes de cinaf.tv :
 *
 *     titles/{titleId}/poster_portrait/{assetId}.jpeg
 *     titles/{titleId}/poster/{assetId}.png
 *
 * Or les œuvres importées en base sont, elles, identifiées par leur dossier
 * Bunny Storage (`FILMS/CLEOPATRA`, `MADAME_SALVADOR`). Les deux systèmes ne
 * partagent aucune clé. Le pont retenu est le **nom de l'œuvre** : le sitemap
 * public de cinaf.tv expose des URLs `/{lang}/titles/{titleId}/{slug}`, d'où
 * l'on tire une table {titleId → slug} rapprochée des titres en base.
 *
 * Le rapprochement se fait en trois passes de confiance décroissante (exact,
 * inclusion, similarité). Un identifiant cinaf.tv n'est jamais attribué deux
 * fois. **Toutes les correspondances non exactes sont listées dans le rapport**
 * afin d'être relues : une affiche attribuée au mauvais film est possible sur
 * les scores les plus bas (`MR_ASSA` ressemble à `mme-assa`).
 *
 * Seules les œuvres importées (`bunnyFolder` renseigné) sont appariées : le
 * contenu des studios garde ses propres visuels. L'URL enregistrée dans
 * `poster` est l'URL publique de l'image, servie par la pull zone de la zone
 * des visuels (ex. `https://cinaf-engine.b-cdn.net/...`).
 *
 * Options :
 *  - `--dry-run`      : calcule et affiche le rapport sans rien écrire en base ;
 *  - `--min-score=80` : seuil (0-100) de la passe « approchée » ;
 *  - `--sitemap=`     : sitemap local à utiliser au lieu de le télécharger ;
 *  - `--fill-missing` : donne en plus une affiche ARBITRAIRE (d'un autre titre)
 *                       à toute œuvre restée sans affiche, contenu studio compris.
 *
 * Accès réseau : téléchargement du sitemap (sauf `--sitemap`) et un ou deux
 * listings Bunny par titre apparié (ainsi que pour les titres de repli avec
 * `--fill-missing`). Zone des visuels : paramètre
 * `app.bunny.images_zone`, hébergée dans une autre région (endpoint Storage
 * propre, voir `config/services.yaml`).
 *
 * Exemple : `php bin/console app:catalogue:import-posters --dry-run`.
 */
#[AsCommand(
    name: 'app:catalogue:import-posters',
    description: 'Renseigne les affiches des œuvres importées depuis la zone Bunny des visuels.',
)]
class ImportCataloguePostersCommand extends Command
{
    /** Sitemap public de cinaf.tv, seule source reliant un identifiant de titre à son nom. */
    private const SITEMAP_URL = 'https://www.cinaf.tv/sitemap.xml';
    /**
     * URL d'une fiche titre en français : groupe 1 = identifiant (UUID de
     * 36 caractères), groupe 2 = slug lisible du titre. Seules les URL `/fr/`
     * sont lues.
     */
    private const TITLE_URL_PATTERN = '#https://www\.cinaf\.tv/fr/titles/([0-9a-f-]{36})/([^<"\s]+)#';

    /** Sous-dossiers d'affiche, du plus adapté au moins adapté (format portrait d'abord). */
    private const POSTER_DIRS = ['poster_portrait', 'poster'];

    /** En deçà, une inclusion de nom n'est pas discriminante (« sin » matcherait tout). */
    private const MIN_CONTAINMENT_LENGTH = 6;

    /**
     * @param string $imagesZone Zone Bunny des visuels (`BUNNY_IMAGES_ZONE`, ex. `cinaf-engine-zone`).
     * @param string $caBundle   Bundle CA pour télécharger le sitemap en HTTPS (indispensable sous Windows).
     */
    public function __construct(
        private readonly BunnyZoneRegistry $zones,
        private readonly FilmRepository $filmRepo,
        private readonly SerieRepository $serieRepo,
        private readonly EntityManagerInterface $em,
        private readonly string $imagesZone,
        private readonly string $caBundle,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'N\'écrit rien en base.');
        $this->addOption(
            'min-score',
            null,
            InputOption::VALUE_REQUIRED,
            'Score minimal (0-100) d\'une correspondance approchée.',
            '80',
        );
        $this->addOption(
            'sitemap',
            null,
            InputOption::VALUE_REQUIRED,
            'Chemin d\'un sitemap local, au lieu de le télécharger.',
        );
        $this->addOption(
            'fill-missing',
            null,
            InputOption::VALUE_NONE,
            'Attribue une affiche arbitraire (titre non apparié) à toute œuvre restée sans affiche, contenu studio compris.',
        );
    }

    /**
     * Enchaîne : chargement des titres cinaf.tv, sélection des œuvres importées,
     * appariement, recherche du fichier d'affiche de chaque titre retenu,
     * écriture (un seul flush) puis rapport.
     *
     * @return int Command::FAILURE si le sitemap est illisible ou vide ;
     *             Command::SUCCESS sinon (y compris sans aucune œuvre importée).
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $minScore = (float) $input->getOption('min-score');
        $fillMissing = (bool) $input->getOption('fill-missing');

        $io->title($dryRun ? 'Import des affiches (DRY-RUN)' : 'Import des affiches');

        try {
            $titles = $this->loadTitles($input->getOption('sitemap'));
        } catch (\Throwable $e) {
            $io->error('Impossible de charger la table des titres : ' . $e->getMessage());
            return Command::FAILURE;
        }
        if ($titles === []) {
            $io->error('Aucun titre trouvé dans le sitemap.');
            return Command::FAILURE;
        }

        $works = $this->importedWorks();
        if ($works === []) {
            $io->warning('Aucune œuvre importée en base — lance d\'abord app:catalogue:import-bunny.');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('%d titres cinaf.tv · %d œuvres en base.', count($titles), count($works)));
        $io->newLine();

        $matches = $this->matchWorks($works, $titles, $minScore);

        // Résolution du fichier d'affiche sur la zone, puis écriture.
        $storage = $this->zones->get($this->imagesZone);
        $applied = 0;
        $noAsset = [];
        // Œuvres ayant reçu une affiche dans cette exécution (en dry-run rien
        // n'est écrit sur l'entité, on suit donc les identités à part).
        $served = [];
        $usedTitles = [];
        foreach ($matches['matched'] as $m) {
            $usedTitles[$m['titleId']] = true;
            $url = $this->resolvePosterUrl($storage, $m['titleId']);

            if ($url === null) {
                $noAsset[] = $m['work']->getTitle();
                continue;
            }

            if (!$dryRun) {
                $m['work']->setPoster($url);
            }
            $served[spl_object_id($m['work'])] = true;
            $applied++;
        }

        $filled = $fillMissing
            ? $this->fillMissing($storage, $titles, $usedTitles, $served, $dryRun)
            : [];

        if (!$dryRun) {
            $this->em->flush();
        }

        $this->report($io, $matches, $applied, $noAsset, $filled, $dryRun);

        return Command::SUCCESS;
    }

    /**
     * Repli : chaque œuvre encore sans affiche (importée ou studio) reçoit
     * l'affiche d'un titre cinaf.tv non consommé par l'appariement. Le visuel
     * ne correspond donc pas à l'œuvre — choix assumé pour ne laisser aucune
     * carte vide. Un titre n'est réutilisé que si le pool est épuisé.
     *
     * @param  list<array{id:string, slug:string, norm:string}> $titles
     * @param  array<string,bool>                              $usedTitles
     * @param  array<int,bool>                                 $served
     * @return list<array{work:Film|Serie, slug:string}>
     */
    private function fillMissing(
        BunnyStorageService $storage,
        array $titles,
        array $usedTitles,
        array $served,
        bool $dryRun,
    ): array {
        // Toutes les œuvres en base, quel que soit leur statut ou leur origine.
        // $served écarte celles déjà servies par l'appariement (utile en dry-run,
        // où leur champ `poster` est resté vide).
        $missing = array_filter(
            array_merge($this->filmRepo->findAll(), $this->serieRepo->findAll()),
            static fn($w) => $w->getPoster() === null && !isset($served[spl_object_id($w)]),
        );
        if ($missing === []) {
            return [];
        }

        // Pool des affiches réellement disponibles parmi les titres libres.
        // On s'arrête dès qu'il y a une affiche par œuvre à servir, pour limiter
        // les appels Bunny.
        $pool = [];
        foreach ($titles as $title) {
            if (isset($usedTitles[$title['id']])) {
                continue;
            }
            $url = $this->resolvePosterUrl($storage, $title['id']);
            if ($url !== null) {
                $pool[] = ['slug' => $title['slug'], 'url' => $url];
            }
            if (count($pool) >= count($missing)) {
                break;
            }
        }
        if ($pool === []) {
            return [];
        }

        $filled = [];
        foreach (array_values($missing) as $i => $work) {
            // Distribution circulaire : le pool n'est réutilisé que s'il est plus petit que la liste.
            $pick = $pool[$i % count($pool)];
            if (!$dryRun) {
                $work->setPoster($pick['url']);
            }
            $filled[] = ['work' => $work, 'slug' => $pick['slug']];
        }
        return $filled;
    }

    /**
     * Première image trouvée pour un titre, format portrait en priorité.
     *
     * Les noms de fichiers étant imprévisibles, il faut lister le dossier :
     * le premier fichier (ordre alphabétique de listContents()) est retenu.
     *
     * @return string|null URL publique de l'image, null si aucun fichier n'a été trouvé.
     */
    private function resolvePosterUrl(BunnyStorageService $storage, string $titleId): ?string
    {
        foreach (self::POSTER_DIRS as $dir) {
            try {
                $listing = $storage->listContents("titles/$titleId/$dir", false);
            } catch (\Throwable) {
                // Toute erreur de listing (réseau, clé refusée…) revient à
                // « pas d'affiche ici » : on essaie le dossier suivant.
                continue;
            }
            if (!empty($listing['files'])) {
                return $storage->getPublicUrl($listing['files'][0]['path']);
            }
        }
        return null;
    }

    /**
     * Œuvres issues de l'import catalogue (le contenu studio garde ses propres
     * visuels et n'est pas concerné).
     *
     * @return list<Film|Serie>
     */
    private function importedWorks(): array
    {
        $films = $this->filmRepo->createQueryBuilder('f')
            ->where('f.bunnyFolder IS NOT NULL')
            ->getQuery()->getResult();
        $series = $this->serieRepo->createQueryBuilder('s')
            ->where('s.bunnyFolder IS NOT NULL')
            ->getQuery()->getResult();

        return array_merge($films, $series);
    }

    /**
     * Table {titleId → slug} extraite du sitemap public de cinaf.tv.
     *
     * Chaque identifiant n'est retenu qu'une fois (première URL rencontrée) ;
     * `norm` est le slug normalisé, prêt pour la comparaison avec les titres
     * en base.
     *
     * @param string|null $localPath Sitemap local (option `--sitemap`) ; null ou vide = téléchargement.
     *
     * @throws \RuntimeException si le fichier local est illisible (les erreurs
     *                           HTTP du téléchargement remontent via Guzzle).
     *
     * @return list<array{id:string, slug:string, norm:string}>
     */
    private function loadTitles(?string $localPath): array
    {
        if ($localPath !== null && $localPath !== '') {
            $xml = file_get_contents($localPath);
            if ($xml === false) {
                throw new \RuntimeException("Sitemap illisible : $localPath");
            }
        } else {
            // CA bundle explicite : PHP n'a pas de magasin de certificats sur Windows.
            $client = new Client(['timeout' => 60, 'verify' => $this->caBundle]);
            $xml = (string) $client->get(self::SITEMAP_URL)->getBody();
        }

        preg_match_all(self::TITLE_URL_PATTERN, $xml, $matches, PREG_SET_ORDER);

        $titles = [];
        $seen = [];
        foreach ($matches as $m) {
            if (isset($seen[$m[1]])) {
                continue;
            }
            $seen[$m[1]] = true;
            $titles[] = ['id' => $m[1], 'slug' => $m[2], 'norm' => $this->normalize($m[2])];
        }
        return $titles;
    }

    /**
     * Rapproche œuvres et titres en trois passes de confiance décroissante.
     * Un titre déjà consommé n'est plus proposé, ce qui évite de coller la même
     * affiche sur plusieurs œuvres.
     *
     * @param  list<Film|Serie>                                    $works
     * @param  list<array{id:string, slug:string, norm:string}>     $titles
     * @return array{matched: list<array{work:Film|Serie, titleId:string, slug:string, confidence:string, score:int}>, unmatched: list<string>}
     */
    private function matchWorks(array $works, array $titles, float $minScore): array
    {
        $usedTitles = [];
        $matched = [];
        $pending = $works;

        // Passe 1 — égalité du nom normalisé.
        $pending = $this->pass($pending, $titles, $usedTitles, $matched, function (string $workNorm, array $title) {
            return $title['norm'] === $workNorm ? 100.0 : null;
        }, 'exact');

        // Passe 2 — inclusion d'un nom dans l'autre, si assez long pour discriminer.
        $pending = $this->pass($pending, $titles, $usedTitles, $matched, function (string $workNorm, array $title) {
            $t = $title['norm'];
            if (strlen($workNorm) < self::MIN_CONTAINMENT_LENGTH || strlen($t) < self::MIN_CONTAINMENT_LENGTH) {
                return null;
            }
            if (!str_contains($t, $workNorm) && !str_contains($workNorm, $t)) {
                return null;
            }
            // Score = recouvrement, pour départager plusieurs inclusions possibles.
            return 100.0 * min(strlen($workNorm), strlen($t)) / max(strlen($workNorm), strlen($t));
        }, 'inclusion');

        // Passe 3 — similarité approximative au-dessus du seuil.
        // similar_text() fournit un pourcentage de ressemblance ; seuil = --min-score.
        $pending = $this->pass($pending, $titles, $usedTitles, $matched, function (string $workNorm, array $title) use ($minScore) {
            similar_text($workNorm, $title['norm'], $pct);
            return $pct >= $minScore ? $pct : null;
        }, 'approché');

        return [
            'matched' => $matched,
            'unmatched' => array_map(static fn($w) => $w->getTitle(), $pending),
        ];
    }

    /**
     * Applique une stratégie de rapprochement à toutes les œuvres restantes et
     * retourne celles encore non appariées.
     *
     * @param  list<Film|Serie>                                $pending
     * @param  list<array{id:string, slug:string, norm:string}> $titles
     * @param  array<string,bool>                              $usedTitles
     * @param  list<array<string,mixed>>                       $matched
     * @return list<Film|Serie>
     */
    private function pass(
        array $pending,
        array $titles,
        array &$usedTitles,
        array &$matched,
        callable $score,
        string $confidence,
    ): array {
        $stillPending = [];

        // Appariement glouton : les œuvres sont traitées dans l'ordre et chacune
        // prend le titre libre au meilleur score (à égalité, le premier trouvé).
        foreach ($pending as $work) {
            $workNorm = $this->normalize($work->getTitle());
            $best = null;
            $bestScore = 0.0;

            foreach ($titles as $title) {
                if (isset($usedTitles[$title['id']])) {
                    continue;
                }
                $s = $score($workNorm, $title);
                if ($s !== null && $s > $bestScore) {
                    $bestScore = $s;
                    $best = $title;
                }
            }

            if ($best === null) {
                $stillPending[] = $work;
                continue;
            }

            $usedTitles[$best['id']] = true;
            $matched[] = [
                'work' => $work,
                'titleId' => $best['id'],
                'slug' => $best['slug'],
                'confidence' => $confidence,
                'score' => (int) round($bestScore),
            ];
        }

        return $stillPending;
    }

    /** Réduit un nom à ses caractères alphanumériques minuscules, sans accents. */
    private function normalize(string $value): string
    {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $value) ?? '');
    }

    /**
     * Affiche le rapport : compteurs par niveau de confiance, puis les listes
     * à relire (correspondances non exactes triées par score croissant, titres
     * sans fichier d'affiche, œuvres sans correspondance, affiches arbitraires).
     *
     * @param array{matched: list<array<string,mixed>>, unmatched: list<string>} $matches
     * @param list<string>                                                      $noAsset
     * @param list<array{work:Film|Serie, slug:string}>                         $filled
     */
    private function report(SymfonyStyle $io, array $matches, int $applied, array $noAsset, array $filled, bool $dryRun): void
    {
        $byConfidence = ['exact' => 0, 'inclusion' => 0, 'approché' => 0];
        foreach ($matches['matched'] as $m) {
            $byConfidence[$m['confidence']]++;
        }

        $io->section('Résumé');
        $io->writeln(sprintf('  Affiches %s : %d', $dryRun ? 'trouvées ' : 'appliquées', $applied));
        $io->writeln(sprintf('  dont exactes         : %d', $byConfidence['exact']));
        $io->writeln(sprintf('  dont par inclusion   : %d', $byConfidence['inclusion']));
        $io->writeln(sprintf('  dont approchées      : %d', $byConfidence['approché']));
        $io->writeln(sprintf('  Sans fichier image   : %d', count($noAsset)));
        $io->writeln(sprintf('  Sans correspondance  : %d', count($matches['unmatched'])));
        if ($filled !== []) {
            $io->writeln(sprintf('  Affiches arbitraires : %d (--fill-missing)', count($filled)));
        }

        // Les correspondances non exactes sont celles qui peuvent se tromper :
        // on les liste systématiquement pour relecture.
        $toReview = array_filter($matches['matched'], static fn($m) => $m['confidence'] !== 'exact');
        if ($toReview !== []) {
            $io->section(sprintf('À relire — correspondances non exactes (%d)', count($toReview)));
            usort($toReview, static fn($a, $b) => $a['score'] <=> $b['score']);
            foreach ($toReview as $m) {
                $io->writeln(sprintf(
                    '  [%3d%%] %-34s → %s',
                    $m['score'],
                    $m['work']->getTitle(),
                    $m['slug'],
                ));
            }
        }

        if ($noAsset !== []) {
            $io->section('Titres appariés mais sans fichier d\'affiche');
            foreach ($noAsset as $t) {
                $io->writeln('  - ' . $t);
            }
        }

        if ($matches['unmatched'] !== []) {
            $io->section('Œuvres sans correspondance');
            foreach ($matches['unmatched'] as $t) {
                $io->writeln('  - ' . $t);
            }
        }

        // Ces affiches ne correspondent pas à l'œuvre : listées pour relecture.
        if ($filled !== []) {
            $io->section(sprintf('Affiches arbitraires attribuées (%d)', count($filled)));
            foreach ($filled as $f) {
                $io->writeln(sprintf('  %-34s ← %s', $f['work']->getTitle(), $f['slug']));
            }
        }

        if ($dryRun) {
            $io->warning('DRY-RUN — aucune écriture en base.');
        } else {
            $io->success(sprintf(
                '%d affiche(s) enregistrée(s)%s.',
                $applied,
                $filled !== [] ? sprintf(' + %d arbitraire(s)', count($filled)) : '',
            ));
        }
    }
}
