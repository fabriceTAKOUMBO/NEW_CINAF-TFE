<?php
namespace App\Command;

use App\Entity\Film;
use App\Entity\Serie;
use App\Repository\FilmRepository;
use App\Repository\SerieRepository;
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
 */
#[AsCommand(
    name: 'app:catalogue:import-posters',
    description: 'Renseigne les affiches des œuvres importées depuis la zone Bunny des visuels.',
)]
class ImportCataloguePostersCommand extends Command
{
    private const SITEMAP_URL = 'https://www.cinaf.tv/sitemap.xml';
    private const TITLE_URL_PATTERN = '#https://www\.cinaf\.tv/fr/titles/([0-9a-f-]{36})/([^<"\s]+)#';

    /** Sous-dossiers d'affiche, du plus adapté au moins adapté (format portrait d'abord). */
    private const POSTER_DIRS = ['poster_portrait', 'poster'];

    /** En deçà, une inclusion de nom n'est pas discriminante (« sin » matcherait tout). */
    private const MIN_CONTAINMENT_LENGTH = 6;

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
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $minScore = (float) $input->getOption('min-score');

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
        foreach ($matches['matched'] as $m) {
            $url = null;
            foreach (self::POSTER_DIRS as $dir) {
                try {
                    $listing = $storage->listContents("titles/{$m['titleId']}/$dir", false);
                } catch (\Throwable) {
                    continue;
                }
                if (!empty($listing['files'])) {
                    $url = $storage->getPublicUrl($listing['files'][0]['path']);
                    break;
                }
            }

            if ($url === null) {
                $noAsset[] = $m['work']->getTitle();
                continue;
            }

            if (!$dryRun) {
                $m['work']->setPoster($url);
            }
            $applied++;
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $this->report($io, $matches, $applied, $noAsset, $dryRun);

        return Command::SUCCESS;
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
     * @param array{matched: list<array<string,mixed>>, unmatched: list<string>} $matches
     * @param list<string>                                                      $noAsset
     */
    private function report(SymfonyStyle $io, array $matches, int $applied, array $noAsset, bool $dryRun): void
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

        if ($dryRun) {
            $io->warning('DRY-RUN — aucune écriture en base.');
        } else {
            $io->success(sprintf('%d affiche(s) enregistrée(s).', $applied));
        }
    }
}
