<?php
namespace App\Command;

use App\Service\BunnyZoneRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande de diagnostic `app:bunny:list` : affiche le contenu d'un dossier
 * d'une Storage Zone Bunny (dossiers, puis tableau des fichiers avec type,
 * taille, date de modification et chemin). Lecture seule.
 *
 * Utile pour vérifier qu'une zone est bien configurée (AccessKey, région /
 * endpoint) ou pour inspecter l'arborescence avant un import de catalogue.
 * Équivalent console de l'explorateur admin `/api/admin/bunny/*`.
 *
 * Argument et options :
 *  - `path` (facultatif)   : dossier à lister, racine de la zone par défaut ;
 *  - `--zone` / `-z`       : zone à interroger (sinon la zone par défaut) ;
 *  - `--recursive` / `-r`  : descend dans tous les sous-dossiers (un appel HTTP par dossier) ;
 *  - `--type` / `-t`       : ne garde que les fichiers de ce type (image, video, audio, document) ;
 *  - `--list-zones`        : affiche les zones déclarées puis s'arrête.
 *
 * Exemples : `php bin/console app:bunny:list --list-zones`,
 * `php bin/console app:bunny:list FILMS -z cinaftv-movies -t video`.
 *
 * Codes de sortie : 0 succès, 1 erreur d'accès à Bunny, 2 zone inconnue.
 */
#[AsCommand(
    name: 'app:bunny:list',
    description: 'Liste le contenu d\'une Storage Zone Bunny (diagnostic multi-zones).',
)]
class BunnyListCommand extends Command
{
    public function __construct(private readonly BunnyZoneRegistry $zones)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Chemin du dossier à lister', '')
            ->addOption('zone', 'z', InputOption::VALUE_REQUIRED, 'Storage Zone à interroger (défaut = zone par défaut configurée)')
            ->addOption('recursive', 'r', InputOption::VALUE_NONE, 'Listing récursif')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Filtre par type (image|video|audio|document)')
            ->addOption('list-zones', null, InputOption::VALUE_NONE, 'Affiche les zones configurées et termine');
    }

    /**
     * Exécute le listing (ou l'affichage des zones avec `--list-zones`).
     *
     * @return int Command::SUCCESS, Command::INVALID si la zone est inconnue,
     *             Command::FAILURE si l'appel à Bunny échoue.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Mode « inventaire » : aucune connexion à Bunny, lecture de la configuration seule.
        if ($input->getOption('list-zones')) {
            $default = $this->zones->getDefaultZoneName();
            $io->title('Storage Zones Bunny configurées');
            foreach ($this->zones->getZoneNames() as $z) {
                $marker = $z === $default ? ' (default)' : '';
                $io->writeln("  - $z$marker");
            }
            return Command::SUCCESS;
        }

        $zoneName = $input->getOption('zone') ?: $this->zones->getDefaultZoneName();
        $path = (string) $input->getArgument('path');
        $recursive = (bool) $input->getOption('recursive');
        $type = $input->getOption('type');

        try {
            $service = $this->zones->get($zoneName);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::INVALID;
        }

        $io->title("Bunny Storage [$zoneName] — listing '$path'" . ($recursive ? ' (recursive)' : ''));

        try {
            $result = $service->listContents($path, $recursive);
        } catch (\Throwable $e) {
            $io->error('Échec de la connexion Bunny Storage : ' . $e->getMessage());
            return Command::FAILURE;
        }

        $dirs = $result['directories'];
        $files = $result['files'];

        // Le filtre par type ne s'applique qu'aux fichiers ; les dossiers restent tous affichés.
        if ($type) {
            $files = array_values(array_filter($files, fn($f) => $f['type'] === $type));
        }

        if ($dirs) {
            $io->section('Dossiers (' . count($dirs) . ')');
            foreach ($dirs as $d) {
                $io->writeln("  📁 {$d['path']}");
            }
        }

        if ($files) {
            $io->section('Fichiers (' . count($files) . ')');
            $rows = [];
            foreach ($files as $f) {
                $rows[] = [
                    $f['type'],
                    $f['name'],
                    $this->fmtSize($f['size']),
                    $f['lastModified'] ? date('Y-m-d H:i', $f['lastModified']) : '-',
                    $f['path'],
                ];
            }
            $io->table(['Type', 'Nom', 'Taille', 'Modifié', 'Path'], $rows);
        } else {
            $io->writeln('Aucun fichier trouvé.');
        }

        $io->success(sprintf(
            '[%s] %d dossier(s), %d fichier(s) trouvé(s).',
            $zoneName,
            count($dirs),
            count($files),
        ));
        return Command::SUCCESS;
    }

    /** Taille lisible par un humain (B, KB, MB, GB en base 1024). */
    private function fmtSize(int $bytes): string
    {
        if ($bytes < 1024) return "$bytes B";
        if ($bytes < 1024 * 1024) return sprintf('%.1f KB', $bytes / 1024);
        if ($bytes < 1024 * 1024 * 1024) return sprintf('%.1f MB', $bytes / 1024 / 1024);
        return sprintf('%.2f GB', $bytes / 1024 / 1024 / 1024);
    }
}
