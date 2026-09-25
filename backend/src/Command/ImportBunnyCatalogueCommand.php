<?php
namespace App\Command;

use App\Entity\Episode;
use App\Entity\Film;
use App\Entity\FilmPart;
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
 * Phase F — Import du catalogue Bunny vers la base de données (96 œuvres lors
 * de la Phase F ; 185 depuis la séparation films / séries du 2026-08-21).
 *
 * Pré-requis :
 *  - 10 studios actifs en DB (chargés via `doctrine:fixtures:load`).
 *  - AccessKey Bunny configurée pour la zone `cinaftv-movies` (.env.local).
 *
 * Logique :
 *  - Parcourt l'arborescence Bunny via {@see BunnyCatalogueService::listAllWorks()},
 *    qui fournit la classification film / série, les parties et la bande-annonce.
 *  - Pour chaque œuvre : attribue un studio de manière
 *    déterministe (`crc32($slug) % 10` → index dans la liste triée par slug).
 *  - Idempotent : skip si un Film/Serie existe déjà avec le même `bunnyFolder`
 *    (= dossier Bunny racine de l'œuvre, ex: "12_CAS" ou "FILMS/CLEOPATRA").
 *  - Slugs déduplication : suffixe `-2`, `-3`, etc. en cas de collision.
 *  - Pour les films, crée une FilmPart par vidéo jouable ; la bande-annonce
 *    va dans `trailerVideoId`. Un film sans aucune vidéo jouable n'est pas créé.
 *  - Pour les séries, crée Season + Episode descendants.
 *  - Pas de poster / synopsis détaillé / durée — placeholders uniquement.
 *  - Les œuvres sont créées directement en statut PUBLISHED.
 *
 * Options : `--dry-run` (simulation, aucune écriture) et `--purge` (supprime
 * d'abord les œuvres d'un import précédent ; le contenu des studios n'est
 * jamais touché).
 *
 * Sortie : compteurs (créés / skipped / errors) + liste détaillée si --dry-run.
 * S'y ajoutent le nombre de dossiers sans vidéo et la liste des films en
 * plusieurs parties. Code de sortie FAILURE si un pré-requis manque ou si une
 * œuvre a échoué (hors dry-run).
 *
 * Exemple : `php bin/console app:catalogue:import-bunny --dry-run`.
 */
#[AsCommand(
    name: 'app:catalogue:import-bunny',
    description: 'Importe le catalogue Bunny (96 œuvres) vers la DB et les rattache aux 10 studios fictifs.',
)]
class ImportBunnyCatalogueCommand extends Command
{
    /** Nombre de studios entre lesquels les œuvres importées sont réparties. */
    private const REQUIRED_STUDIOS = 10;
    /**
     * Synopsis provisoire des œuvres importées. Doit rester identique à
     * CatalogueDiscoverController::IMPORT_PLACEHOLDER_SYNOPSIS, qui le masque
     * sur la fiche publique.
     */
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
        $this->addOption(
            'purge',
            null,
            InputOption::VALUE_NONE,
            'Supprime les œuvres issues d\'un import précédent avant de réimporter '
            . '(le contenu uploadé par les studios n\'est jamais touché).',
        );
    }

    /**
     * Déroule l'import : contrôle des studios, purge éventuelle, listing
     * Bunny, création des œuvres puis rapport.
     *
     * Toutes les créations sont écrites en base par un seul flush final ; une
     * œuvre en erreur est comptée et n'interrompt pas les suivantes.
     *
     * @return int Command::SUCCESS, ou Command::FAILURE (studios manquants,
     *             erreur inattendue au listing, ou au moins une œuvre en erreur hors dry-run).
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $purge = (bool) $input->getOption('purge');

        // Studios actifs triés par slug : l'ordre fixe l'index visé par crc32($slug) % 10.
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

        if ($purge) {
            $this->purgeImported($io, $dryRun);
        }

        // listAllWorks() absorbe lui-même les erreurs de listing Bunny (résultat
        // partiel, voire vide) : ce catch ne couvre que les erreurs inattendues.
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
        $unplayable = [];
        $multiPart = [];
        $errors = [];
        $details = [];
        // Slugs déjà pris en base, films ET séries confondus : la fiche publique
        // cherche une œuvre par slug sans savoir s'il s'agit d'un film ou d'une série.
        $usedSlugs = $this->loadExistingSlugs();

        // Une erreur sur une œuvre est consignée et n'interrompt pas les suivantes.
        foreach ($works as $work) {
            try {
                $result = $this->processWork($work, $studios, $usedSlugs, $dryRun);
                if ($result['action'] === 'skip') {
                    $skipped++;
                } elseif ($result['action'] === 'empty') {
                    // Dossier ne contenant qu'une bande-annonce : rien à lire.
                    $unplayable[] = $result['title'];
                } else {
                    $created[$result['kind']]++;
                    if ($result['parts'] > 1) {
                        $multiPart[] = sprintf('%s (%d parties)', $result['title'], $result['parts']);
                    }
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
            // Flush unique : toutes les entités persistées par processWork() sont écrites ici.
            $this->em->flush();
        }

        $io->newLine();
        $io->section('Résumé');
        $io->writeln(sprintf('  Films créés   : %d', $created['film']));
        $io->writeln(sprintf('  Séries créées : %d', $created['serie']));
        $io->writeln(sprintf('  Skipped       : %d', $skipped));
        $io->writeln(sprintf('  Sans vidéo    : %d', count($unplayable)));
        $io->writeln(sprintf('  Erreurs       : %d', count($errors)));

        if (!empty($multiPart)) {
            $io->section(sprintf('Films en plusieurs parties (%d)', count($multiPart)));
            foreach ($multiPart as $label) {
                $io->writeln('  - ' . $label);
            }
        }

        if (!empty($unplayable)) {
            $io->section('Dossiers sans vidéo principale (bande-annonce seule)');
            foreach ($unplayable as $title) {
                $io->writeln('  - ' . $title);
            }
        }

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
     * Actions renvoyées : `skip` (dossier déjà importé), `empty` (film sans
     * vidéo jouable, signalé mais non créé) ou `create`. En dry-run, le slug
     * et le studio sont calculés mais rien n'est persisté.
     *
     * @param array{slug:string,title:string,kind:string,path:string,trailerPath:?string,parts:list<array<string,mixed>>,seasons:list<array<string,mixed>>} $work
     * @param list<Studio>                                                                                                                                 $studios
     * @param array<string,bool>                                                                                                                           $usedSlugs Référence pour déduplication
     *
     * @return array{action:'create'|'skip'|'empty', kind:'film'|'serie', title:string, slug:string, studio:?string, parts:int}
     */
    private function processWork(array $work, array $studios, array &$usedSlugs, bool $dryRun): array
    {
        $bunnyFolder = $work['path'];

        // Idempotence : le dossier Bunny racine identifie l'œuvre importée.
        $existingFilm = $this->filmRepo->findOneBy(['bunnyFolder' => $bunnyFolder]);
        if ($existingFilm !== null) {
            return [
                'action' => 'skip',
                'kind' => 'film',
                'title' => $work['title'],
                'slug' => $existingFilm->getSlug(),
                'studio' => $existingFilm->getStudio()?->getSlug(),
                'parts' => $existingFilm->getParts()->count(),
            ];
        }
        $existingSerie = $this->findSerieByBunnyPath($bunnyFolder);
        if ($existingSerie !== null) {
            return [
                'action' => 'skip',
                'kind' => 'serie',
                'title' => $work['title'],
                'slug' => $existingSerie->getSlug(),
                'studio' => $existingSerie->getStudio()?->getSlug(),
                'parts' => 0,
            ];
        }

        // Un dossier film ne contenant qu'une bande-annonce n'est pas lisible :
        // on le signale plutôt que de créer une fiche sans vidéo.
        if ($work['kind'] === 'film' && $work['parts'] === []) {
            return [
                'action' => 'empty',
                'kind' => 'film',
                'title' => $work['title'],
                'slug' => $work['slug'],
                'studio' => null,
                'parts' => 0,
            ];
        }

        // Slug déduplication.
        $slug = $this->dedupeSlug($work['slug'], $usedSlugs);
        $usedSlugs[$slug] = true;

        // Studio attribution déterministe : crc32($slug) % 10.
        // Un même slug retombe toujours sur le même studio ; abs() protège d'un
        // crc32() négatif sur une plateforme 32 bits.
        $studioIdx = abs(crc32($slug)) % count($studios);
        $studio = $studios[$studioIdx];

        $partCount = $work['kind'] === 'film' ? count($work['parts']) : 0;

        if (!$dryRun) {
            if ($work['kind'] === 'serie') {
                $this->createSerie($work, $slug, $studio);
            } else {
                $this->createFilm($work, $slug, $studio);
            }
        }

        return [
            'action' => 'create',
            'kind' => $work['kind'],
            'title' => $work['title'],
            'slug' => $slug,
            'studio' => $studio->getSlug(),
            'parts' => $partCount,
        ];
    }

    /**
     * Supprime les œuvres issues d'un import précédent, afin de pouvoir
     * réimporter avec la classification corrigée (l'idempotence les ignorerait
     * sinon). Le contenu uploadé par les studios n'est jamais touché : il porte
     * un chemin préfixé `studios/` et n'a pas de `bunnyFolder`.
     *
     * Les lignes créées avant l'introduction de `bunnyFolder` sont rattrapées
     * via leur chemin Bunny hérité (films) ou le détournement historique de
     * `trailerVideoId` (séries).
     */
    private function purgeImported(SymfonyStyle $io, bool $dryRun): void
    {
        // Critère « importé » : `bunnyFolder` renseigné, ou (lignes antérieures à
        // ce champ) `bunnyVideoId` du film / `trailerVideoId` de la série hors du
        // préfixe `studios/` réservé aux uploads des studios.
        $films = $this->filmRepo->createQueryBuilder('f')
            ->where('f.bunnyFolder IS NOT NULL')
            ->orWhere('(f.bunnyVideoId IS NOT NULL AND f.bunnyVideoId NOT LIKE :studio)')
            ->setParameter('studio', 'studios/%')
            ->getQuery()
            ->getResult();

        $series = $this->serieRepo->createQueryBuilder('s')
            ->where('s.bunnyFolder IS NOT NULL')
            ->orWhere('(s.trailerVideoId IS NOT NULL AND s.trailerVideoId NOT LIKE :studio)')
            ->setParameter('studio', 'studios/%')
            ->getQuery()
            ->getResult();

        $io->writeln(sprintf(
            'Purge : %d film(s) et %d série(s) issus du catalogue Bunny.',
            count($films),
            count($series),
        ));

        if ($dryRun) {
            $io->warning('DRY-RUN — purge non exécutée.');
            return;
        }

        foreach ($films as $film) {
            $this->em->remove($film);
        }
        foreach ($series as $serie) {
            $this->em->remove($serie);
        }
        // Flush immédiat, distinct de celui de l'import : la purge est écrite
        // avant même que le catalogue Bunny soit listé.
        $this->em->flush();
        $io->writeln('  → purge effectuée.');
        $io->newLine();
    }

    /**
     * Crée un Film publié, rattaché au studio attribué, et une FilmPart par
     * vidéo jouable (numérotées 1..N dans l'ordre de `parts`).
     *
     * `bunnyFolder` reçoit le dossier de l'œuvre (clé d'idempotence),
     * `bunnyVideoId` le chemin de la 1re partie et `trailerVideoId` celui de
     * la bande-annonce (ou null). Les entités sont seulement persistées : le
     * flush est fait par execute().
     *
     * @param array<string, mixed> $work Œuvre de type film issue de listAllWorks(), avec au moins une partie.
     */
    private function createFilm(array $work, string $slug, Studio $studio): Film
    {
        $parts = $work['parts'];

        $film = new Film();
        $film->setTitle($work['title']);
        $film->setSlug($slug);
        $film->setSynopsis(self::PLACEHOLDER_SYNOPSIS);
        // Champs NOT NULL avec defaults : year=0 (placeholder, à éditer plus tard), duration=0.
        $film->setYear(0);
        $film->setDuration(0);
        $film->setPoster(null);
        $film->setBunnyFolder($work['path']);
        // Vidéo principale = 1re partie ; les éventuelles suivantes sont
        // portées par les FilmPart ci-dessous.
        $film->setBunnyVideoId($parts[0]['path']);
        $film->setTrailerVideoId($work['trailerPath']);
        $film->setStudio($studio);
        $film->setStatus(Film::STATUS_PUBLISHED);
        $film->setPublishedAt(new \DateTimeImmutable());

        $this->em->persist($film);

        $number = 1;
        foreach ($parts as $part) {
            $filmPart = new FilmPart();
            $filmPart->setNumber($number);
            $filmPart->setTitle($part['name']);
            $filmPart->setBunnyVideoId($part['path']);
            $film->addPart($filmPart);
            $this->em->persist($filmPart);
            $number++;
        }

        return $film;
    }

    /**
     * Crée une Serie publiée, rattachée au studio attribué, avec ses saisons
     * et ses épisodes.
     *
     * Saisons et épisodes sont numérotés 1..N dans l'ordre fourni par
     * listAllWorks() (ordre naturel des noms de dossiers, pas forcément le
     * numéro réel de l'épisode). Une saison prend le nom de son dossier Bunny
     * (ou « Saison 1 » / « Épisodes hors saison » pour les épisodes à plat),
     * un épisode le nom du sien ; `bunnyVideoId` d'un épisode est le chemin de
     * ce dossier. Persistance seule, le flush est fait par execute().
     *
     * @param array<string, mixed> $work Œuvre de type série issue de listAllWorks().
     */
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
        $serie->setBunnyFolder($work['path']);
        $serie->setTrailerVideoId($work['trailerPath']);

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

    /**
     * Renvoie un slug libre : le slug de base nettoyé, ou suffixé `-2`, `-3`…
     * s'il est déjà pris. N'enregistre pas le résultat dans `$usedSlugs`
     * (c'est à l'appelant de le faire).
     *
     * @param array<string, bool> $usedSlugs Slugs déjà utilisés (clés).
     */
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
     * Cherche une Serie par son dossier Bunny racine.
     */
    private function findSerieByBunnyPath(string $path): ?Serie
    {
        return $this->serieRepo->findOneBy(['bunnyFolder' => $path]);
    }
}
