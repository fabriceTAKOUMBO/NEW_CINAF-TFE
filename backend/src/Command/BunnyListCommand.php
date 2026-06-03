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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

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

    private function fmtSize(int $bytes): string
    {
        if ($bytes < 1024) return "$bytes B";
        if ($bytes < 1024 * 1024) return sprintf('%.1f KB', $bytes / 1024);
        if ($bytes < 1024 * 1024 * 1024) return sprintf('%.1f MB', $bytes / 1024 / 1024);
        return sprintf('%.2f GB', $bytes / 1024 / 1024 / 1024);
    }
}
