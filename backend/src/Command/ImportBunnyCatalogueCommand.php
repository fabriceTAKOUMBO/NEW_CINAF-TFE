<?php
namespace App\Command;

use App\Entity\Episode;
use App\Entity\Film;
use App\Entity\Season;
use App\Entity\Serie;
use App\Entity\Studio;
use App\Repository\FilmRepository;
use App\Repository\SerieRepository;
use App\Repository\StudioRepository;
use App\Service\BunnyCatalogueService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Phase F — Import du catalogue Bunny (96 œuvres) vers la base de données.
 *
 * Pré-requis :
 *  - 10 studios actifs en DB (chargés via `doctrine:fixtures:load`).
 *  - AccessKey Bunny configurée pour la zone `cinaftv-movies` (.env.local).
 *
 * Logique :
 *  - Parcourt l'arborescence Bunny via {@see BunnyCatalogueService::listAllWorks()}.
 *  - Pour chaque œuvre : détecte film vs série, attribue un studio de manière
 *    déterministe (`crc32($slug) % 10` → index dans la liste triée par slug).
 *  - Idempotent : skip si un Film/Serie existe déjà avec le même `bunnyVideoId`
 *    (= path Bunny racine, ex: "12_CAS" ou "A_bientot").
 *  - Slugs déduplication : suffixe `-2`, `-3`, etc. en cas de collision.
 *  - Pour les séries, crée Season + Episode descendants.
 *  - Pas de poster / synopsis détaillé / durée — placeholders uniquement.
 *
 * Sortie : compteurs (créés / skipped / errors) + liste détaillée si --dry-run.
 */
#[AsCommand(
    name: 'app:catalogue:import-bunny',
    description: 'Importe le catalogue Bunny (96 œuvres) vers la DB et les rattache aux 10 studios fictifs.',
)]
class ImportBunnyCatalogueCommand extends Command
{
    private const REQUIRED_STUDIOS = 10;
    private const PLACEHOLDER_SYNOPSIS = 'Importé depuis le catalogue Bunny CINAF.';

    public function __construct(
        private readonly BunnyCatalogueService $catalogue,
        private readonly StudioRepository $studioRepo,
        private readonly FilmRepository $filmRepo,
        private readonly SerieRepository $serieRepo,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Affiche les actions prévues sans rien écrire en base.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $studios = $this->studioRepo->findActiveOrdered();
        if (count($studios) < self::REQUIRED_STUDIOS) {
            $io->error(sprintf(
                '%d studios fictifs requis (trouvé %d). Lance d\'abord `doctrine:fixtures:load`.',
                self::REQUIRED_STUDIOS,
                count($studios),
            ));
            return Command::FAILURE;
        }

        // On garde uniquement les 10 premiers studios (par slug ASC) pour
        // garantir une distribution stable même si plus de 10 existent.
        $studios = array_slice($studios, 0, self::REQUIRED_STUDIOS);

        $io->title($dryRun ? 'Import Bunny → DB (DRY-RUN)' : 'Import Bunny → DB');

        try {
            $works = $this->catalogue->listAllWorks();
        } catch (\Throwable $e) {
            $io->error('Échec listing Bunny : ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->writeln(sprintf('%d œuvres détectées sur Bunny.', count($works)));
        $io->newLine();

        $created = ['film' => 0, 'serie' => 0];
        $skipped = 0;
        $errors = [];
        $details = [];
        $usedSlugs = $this->loadExistingSlugs();

        foreach ($works as $work) {
            try {
                $result = $this->processWork($work, $studios, $usedSlugs, $dryRun);
                if ($result['action'] === 'skip') {
                    $skipped++;
                } else {
                    $created[$result['kind']]++;
                }
                $details[] = $result;
            } catch (\Throwable $e) {
                $errors[] = ['title' => $work['title'], 'error' => $e->getMessage()];
                $io->writeln(sprintf('  <error>✗ %s : %s</error>', $work['title'], $e->getMessage()));
            }
        }

        if ($dryRun) {
            $io->section('Détail des actions prévues');
            foreach ($details as $d) {
                $studioInfo = $d['studio'] ? ' → studio ' . $d['studio'] : '';
                $io->writeln(sprintf(
                    '  [%s] %s (slug=%s, kind=%s)%s',
                    strtoupper($d['action']),
                    $d['title'],
                    $d['slug'],
                    $d['kind'],
                    $studioInfo,
                ));
            }
        } else {
            $this->em->flush();
        }

        $io->newLine();
        $io->section('Résumé');
        $io->writeln(sprintf('  Films créés   : %d', $created['film']));
        $io->writeln(sprintf('  Séries créées : %d', $created['serie']));
        $io->writeln(sprintf('  Skipped       : %d', $skipped));
        $io->writeln(sprintf('  Erreurs       : %d', count($errors)));

        if (!empty($errors)) {
            $io->section('Erreurs détaillées');
            foreach ($errors as $err) {
                $io->writeln(sprintf('  - %s : %s', $err['title'], $err['error']));
            }
        }

        if ($dryRun) {
            $io->warning('DRY-RUN — aucune écriture en base.');
        } else {
            $io->success(sprintf(
                '%d œuvres importées (%d skipped, %d erreurs).',
                $created['film'] + $created['serie'],
                $skipped,
                count($errors),
            ));
        }

        return count($errors) > 0 && !$dryRun ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Traite une œuvre Bunny : skip si déjà importée, sinon crée Film ou Serie.
     *
     * @param array{slug:string,title:string,kind:string,path:string,seasons:list<array<string,mixed>>} $work
     * @param list<Studio>                                                                              $studios
     * @param array<string,bool>                                                                        $usedSlugs Référence pour déduplication
     *
     * @return array{action:'create'|'skip', kind:'film'|'serie', title:string, slug:string, studio:?string}
     */
    private function processWork(array $work, array $studios, array &$usedSlugs, bool $dryRun): array
    {
        $bunnyVideoId = $work['path'];

        // Idempotence : si une entité existe déjà avec ce bunnyVideoId, skip.
        $existingFilm = $this->filmRepo->findOneBy(['bunnyVideoId' => $bunnyVideoId]);
        if ($existingFilm !== null) {
            return [
                'action' => 'skip',
                'kind' => 'film',
                'title' => $work['title'],
                'slug' => $existingFilm->getSlug(),
                'studio' => $existingFilm->getStudio()?->getSlug(),
            ];
        }
        // Pour Serie, on stocke bunnyVideoId sur la Serie aussi via trailerVideoId
        // OU on vérifie via slug de path. Comme Serie n'a pas bunnyVideoId direct,
        // on utilise le slug pour vérifier.
        $existingSerie = $this->findSerieByBunnyPath($bunnyVideoId);
        if ($existingSerie !== null) {
            return [
                'action' => 'skip',
                'kind' => 'serie',
                'title' => $work['title'],
                'slug' => $existingSerie->getSlug(),
                'studio' => $existingSerie->getStudio()?->getSlug(),
            ];
        }

        // Slug déduplication.
        $slug = $this->dedupeSlug($work['slug'], $usedSlugs);
        $usedSlugs[$slug] = true;

        // Studio attribution déterministe : crc32($slug) % 10.
        $studioIdx = abs(crc32($slug)) % count($studios);
        $studio = $studios[$studioIdx];

        if ($dryRun) {
            return [
                'action' => 'create',
                'kind' => $work['kind'],
                'title' => $work['title'],
                'slug' => $slug,
                'studio' => $studio->getSlug(),
            ];
        }

        if ($work['kind'] === 'serie') {
            $this->createSerie($work, $slug, $studio);
            return [
                'action' => 'create',
                'kind' => 'serie',
                'title' => $work['title'],
                'slug' => $slug,
                'studio' => $studio->getSlug(),
            ];
        }

        $this->createFilm($work, $slug, $studio);
        return [
            'action' => 'create',
            'kind' => 'film',
            'title' => $work['title'],
            'slug' => $slug,
            'studio' => $studio->getSlug(),
        ];
    }

    private function createFilm(array $work, string $slug, Studio $studio): Film
    {
        $film = new Film();
        $film->setTitle($work['title']);
        $film->setSlug($slug);
        $film->setSynopsis(self::PLACEHOLDER_SYNOPSIS);
        // Champs NOT NULL avec defaults : year=0 (placeholder, à éditer plus tard), duration=0.
        $film->setYear(0);
        $film->setDuration(0);
        $film->setPoster(null);
        $film->setBunnyVideoId($work['path']);
        $film->setStudio($studio);
        $film->setStatus(Film::STATUS_PUBLISHED);
        $film->setPublishedAt(new \DateTimeImmutable());

        $this->em->persist($film);
        return $film;
    }

    private function createSerie(array $work, string $slug, Studio $studio): Serie
    {
        $serie = new Serie();
        $serie->setTitle($work['title']);
        $serie->setSlug($slug);
        $serie->setSynopsis(self::PLACEHOLDER_SYNOPSIS);
        $serie->setYear(0);
        $serie->setPoster(null);
        $serie->setStudio($studio);
        $serie->setStatus(Serie::STATUS_PUBLISHED);
        $serie->setPublishedAt(new \DateTimeImmutable());
        // Stocke le path racine Bunny dans trailerVideoId (la Serie n'a pas bunnyVideoId direct).
        $serie->setTrailerVideoId($work['path']);

        $this->em->persist($serie);

        // Saisons + épisodes.
        $seasonNumber = 1;
        foreach ($work['seasons'] as $seasonData) {
            $season = new Season();
            $season->setSerie($serie);
            $season->setNumber($seasonNumber);
            $season->setTitle($seasonData['name']);
            $this->em->persist($season);

            $episodeNumber = 1;
            foreach ($seasonData['episodes'] as $episodeData) {
                $episode = new Episode();
                $episode->setSeason($season);
                $episode->setNumber($episodeNumber);
                $episode->setTitle($episodeData['name']);
                $episode->setBunnyVideoId($episodeData['path']);
                $this->em->persist($episode);
                $episodeNumber++;
            }
            $seasonNumber++;
        }
        $serie->setNbSeasons(count($work['seasons']));

        return $serie;
    }

    /**
     * Charge tous les slugs Film + Serie existants pour la déduplication.
     *
     * @return array<string,bool>
     */
    private function loadExistingSlugs(): array
    {
        $slugs = [];
        $filmSlugs = $this->em->createQueryBuilder()
            ->select('f.slug')
            ->from(Film::class, 'f')
            ->getQuery()
            ->getArrayResult();
        foreach ($filmSlugs as $row) {
            $slugs[$row['slug']] = true;
        }
        $serieSlugs = $this->em->createQueryBuilder()
            ->select('s.slug')
            ->from(Serie::class, 's')
            ->getQuery()
            ->getArrayResult();
        foreach ($serieSlugs as $row) {
            $slugs[$row['slug']] = true;
        }
        return $slugs;
    }

    private function dedupeSlug(string $base, array $usedSlugs): string
    {
        // On re-slugifie via AsciiSlugger pour cohérence (le service Bunny
        // utilisait iconv ; AsciiSlugger est plus moderne et déterministe).
        $slugger = new AsciiSlugger();
        $cleaned = strtolower((string) $slugger->slug($base));
        if ($cleaned === '') {
            $cleaned = 'item';
        }

        if (!isset($usedSlugs[$cleaned])) {
            return $cleaned;
        }
        $i = 2;
        while (isset($usedSlugs["$cleaned-$i"])) {
            $i++;
        }
        return "$cleaned-$i";
    }

    /**
     * Cherche une Serie par bunny path racine (stocké dans trailerVideoId).
     */
    private function findSerieByBunnyPath(string $path): ?Serie
    {
        return $this->serieRepo->findOneBy(['trailerVideoId' => $path]);
    }
}
