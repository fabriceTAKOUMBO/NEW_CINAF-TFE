<?php

namespace App\Tests\Command;

use App\Command\ImportBunnyCatalogueCommand;
use App\Entity\Film;
use App\Entity\Serie;
use App\Entity\Studio;
use App\Entity\User;
use App\Repository\FilmRepository;
use App\Repository\SerieRepository;
use App\Repository\StudioRepository;
use App\Service\BunnyCatalogueService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests unitaires pour la commande `app:catalogue:import-bunny`.
 *
 * Mock le {@see BunnyCatalogueService} pour s'affranchir de la connexion
 * réelle à Bunny — les tests vérifient :
 *  - la garde "≥10 studios actifs requis"
 *  - l'idempotence d'un import double
 *  - la déduplication des slugs
 *  - la distribution déterministe via crc32
 *  - le mode --dry-run (aucune écriture)
 */
class ImportBunnyCatalogueCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->resetTestDatabase();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function test_command_fails_without_10_studios(): void
    {
        // Crée seulement 5 studios.
        $this->seedStudios(5);

        $tester = $this->buildCommandTester(
            $this->mockCatalogueWithWorks([['name' => 'WORK_A', 'kind' => 'film']]),
        );
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode); // FAILURE
        $this->assertStringContainsString('10 studios fictifs requis', $tester->getDisplay());
    }

    public function test_dry_run_makes_no_writes(): void
    {
        $this->seedStudios(10);

        $works = [
            ['name' => 'DryRunFilm', 'kind' => 'film'],
            ['name' => 'DryRunSerie', 'kind' => 'serie', 'seasons' => [
                ['name' => 'Saison_1', 'episodes' => [
                    ['name' => 'Episode01'],
                ]],
            ]],
        ];

        $tester = $this->buildCommandTester($this->mockCatalogueWithWorks($works));
        $exitCode = $tester->execute(['--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('DRY-RUN', $tester->getDisplay());
        $this->assertStringContainsString('[CREATE]', $tester->getDisplay());

        // Vérifie qu'AUCUNE entité n'a été créée en DB.
        $this->em->clear();
        /** @var FilmRepository $filmRepo */
        $filmRepo = $this->em->getRepository(Film::class);
        /** @var SerieRepository $serieRepo */
        $serieRepo = $this->em->getRepository(Serie::class);
        $this->assertSame(0, $filmRepo->count([]));
        $this->assertSame(0, $serieRepo->count([]));
    }

    public function test_import_creates_film_entities_with_studio_assignment(): void
    {
        $studios = $this->seedStudios(10);

        $works = [
            ['name' => 'TEST_FILM_1', 'kind' => 'film'],
            ['name' => 'TEST_FILM_2', 'kind' => 'film'],
        ];

        $tester = $this->buildCommandTester($this->mockCatalogueWithWorks($works));
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);

        $this->em->clear();
        /** @var FilmRepository $filmRepo */
        $filmRepo = $this->em->getRepository(Film::class);
        $films = $filmRepo->findAll();
        $this->assertCount(2, $films);

        foreach ($films as $film) {
            $this->assertNotNull($film->getStudio(), 'Each film must have a studio.');
            $this->assertSame(Film::STATUS_PUBLISHED, $film->getStatus());
            $this->assertNotNull($film->getPublishedAt());
        }
    }

    public function test_import_creates_serie_with_seasons_and_episodes(): void
    {
        $this->seedStudios(10);

        $works = [
            ['name' => 'TEST_SERIE', 'kind' => 'serie', 'seasons' => [
                ['name' => 'CAS_1', 'episodes' => [
                    ['name' => 'E01'],
                    ['name' => 'E02'],
                ]],
                ['name' => 'CAS_2', 'episodes' => [
                    ['name' => 'E01'],
                ]],
            ]],
        ];

        $tester = $this->buildCommandTester($this->mockCatalogueWithWorks($works));
        $tester->execute([]);

        $this->em->clear();
        /** @var SerieRepository $serieRepo */
        $serieRepo = $this->em->getRepository(Serie::class);
        $series = $serieRepo->findAll();
        $this->assertCount(1, $series);

        $serie = $series[0];
        $this->assertSame(2, $serie->getNbSeasons());
        $this->assertCount(2, $serie->getSeasons());
        // 2 + 1 = 3 épisodes au total
        $totalEpisodes = 0;
        foreach ($serie->getSeasons() as $season) {
            $totalEpisodes += $season->getEpisodes()->count();
        }
        $this->assertSame(3, $totalEpisodes);
    }

    public function test_import_is_idempotent_second_run_creates_nothing(): void
    {
        $this->seedStudios(10);
        $works = [['name' => 'IDEMPOTENT_FILM', 'kind' => 'film']];

        // Premier import.
        $tester1 = $this->buildCommandTester($this->mockCatalogueWithWorks($works));
        $tester1->execute([]);

        $this->em->clear();
        $count1 = $this->em->getRepository(Film::class)->count([]);
        $this->assertSame(1, $count1);

        // Second import : le mock retourne les mêmes œuvres, doit skip.
        $tester2 = $this->buildCommandTester($this->mockCatalogueWithWorks($works));
        $tester2->execute([]);
        $this->assertStringContainsString('Skipped', $tester2->getDisplay());

        $this->em->clear();
        $count2 = $this->em->getRepository(Film::class)->count([]);
        $this->assertSame(1, $count2, 'Idempotence : second run must create nothing.');
    }

    public function test_slug_deduplication_on_collision(): void
    {
        $this->seedStudios(10);

        // Même nom, kind différent → doivent générer 2 slugs distincts (suffixe -2).
        $works = [
            ['name' => 'DUPLICATE', 'kind' => 'film'],
            ['name' => 'duplicate', 'kind' => 'serie', 'seasons' => [
                ['name' => 'CAS_1', 'episodes' => [['name' => 'E01']]],
            ]],
        ];

        $tester = $this->buildCommandTester($this->mockCatalogueWithWorks($works));
        $tester->execute([]);

        $this->em->clear();
        $film = $this->em->getRepository(Film::class)->findOneBy([]);
        $serie = $this->em->getRepository(Serie::class)->findOneBy([]);
        $this->assertNotNull($film);
        $this->assertNotNull($serie);
        $this->assertNotSame($film->getSlug(), $serie->getSlug(), 'Slugs must be deduplicated.');
        $this->assertMatchesRegularExpression('/^duplicate(-\d+)?$/', $film->getSlug());
        $this->assertMatchesRegularExpression('/^duplicate(-\d+)?$/', $serie->getSlug());
    }

    public function test_crc32_distribution_assigns_to_correct_studio(): void
    {
        $studios = $this->seedStudios(10);
        // Tri par slug ASC pour reproduire l'ordre de findActiveOrdered().
        usort($studios, fn(Studio $a, Studio $b) => strcmp($a->getSlug(), $b->getSlug()));

        // Slug attendu : "MY_DET_FILM" → AsciiSlugger → "my-det-film"
        $expectedSlug = 'my-det-film';
        $expectedIndex = abs(crc32($expectedSlug)) % 10;
        $expectedStudio = $studios[$expectedIndex];

        $works = [['name' => 'MY_DET_FILM', 'kind' => 'film']];

        $tester = $this->buildCommandTester($this->mockCatalogueWithWorks($works));
        $tester->execute([]);

        $this->em->clear();
        $film = $this->em->getRepository(Film::class)->findOneBy(['slug' => $expectedSlug]);
        $this->assertNotNull($film);
        $this->assertSame(
            $expectedStudio->getSlug(),
            $film->getStudio()->getSlug(),
            'Studio attribution must follow crc32($slug) % 10 deterministic rule.',
        );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Mock minimal du BunnyCatalogueService renvoyant des œuvres pré-fabriquées.
     *
     * @param list<array{name:string, kind:string, seasons?:list<array{name:string,episodes:list<array{name:string}>}>}> $works
     */
    private function mockCatalogueWithWorks(array $works): BunnyCatalogueService
    {
        $mock = $this->createMock(BunnyCatalogueService::class);

        $output = [];
        $i = 0;
        foreach ($works as $w) {
            $name = $w['name'];
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '');
            $slug = trim($slug, '-') ?: 'item-' . $i;

            $seasons = [];
            if ($w['kind'] === 'serie') {
                foreach ($w['seasons'] ?? [] as $s) {
                    $episodes = [];
                    foreach ($s['episodes'] as $e) {
                        $episodes[] = ['name' => $e['name'], 'path' => $name . '/' . $s['name'] . '/' . $e['name']];
                    }
                    $seasons[] = ['name' => $s['name'], 'path' => $name . '/' . $s['name'], 'episodes' => $episodes];
                }
            } elseif (!empty($w['seasons'] ?? [])) {
                // Edge case : film ne devrait pas avoir de saisons explicites.
                foreach ($w['seasons'] as $s) {
                    $episodes = [];
                    foreach ($s['episodes'] as $e) {
                        $episodes[] = ['name' => $e['name'], 'path' => $name . '/' . $s['name'] . '/' . $e['name']];
                    }
                    $seasons[] = ['name' => $s['name'], 'path' => $name . '/' . $s['name'], 'episodes' => $episodes];
                }
            }

            $output[] = [
                'slug' => $slug,
                'title' => $name,
                'kind' => $w['kind'],
                'path' => $name,
                'seasons' => $seasons,
            ];
            $i++;
        }

        $mock->method('listAllWorks')->willReturn($output);
        return $mock;
    }

    private function buildCommandTester(BunnyCatalogueService $catalogue): CommandTester
    {
        $kernel = static::$kernel;
        $studioRepo = static::getContainer()->get(StudioRepository::class);
        $filmRepo = static::getContainer()->get(FilmRepository::class);
        $serieRepo = static::getContainer()->get(SerieRepository::class);
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $command = new ImportBunnyCatalogueCommand(
            $catalogue,
            $studioRepo,
            $filmRepo,
            $serieRepo,
            $em,
        );

        $application = new Application($kernel);
        $application->add($command);

        return new CommandTester($application->find('app:catalogue:import-bunny'));
    }

    /**
     * Crée N studios fictifs dans la DB de test (avec leur owner ROLE_CREATEUR).
     *
     * @return list<Studio>
     */
    private function seedStudios(int $count): array
    {
        $hasher = static::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);
        $studios = [];
        for ($i = 1; $i <= $count; $i++) {
            $user = new User();
            $user->setEmail("studio-owner-$i-" . bin2hex(random_bytes(2)) . "@cinaf-test.com");
            $user->setFirstName('Owner');
            $user->setLastName("$i");
            $user->setRoles(['ROLE_CREATEUR']);
            $user->setIsVerified(true);
            $user->setPassword($hasher->hashPassword($user, 'Password123!'));
            $this->em->persist($user);

            $studio = new Studio();
            $studio->setName("Test Studio $i");
            $studio->setSlug(sprintf('z-test-studio-%02d', $i));
            $studio->setOwner($user);
            $studio->setBunnyFolder("studios/z-test-studio-$i-" . bin2hex(random_bytes(2)) . '/');
            $studio->setIsActive(true);
            $this->em->persist($studio);
            $studios[] = $studio;
        }
        $this->em->flush();
        return $studios;
    }

    /**
     * Reset la DB de test : supprime film, serie, season, episode, studio, user.
     * Plus rapide qu'un drop/create complet (qui prendrait plusieurs secondes).
     */
    private function resetTestDatabase(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM episode');
        $conn->executeStatement('DELETE FROM season');
        $conn->executeStatement('DELETE FROM serie');
        $conn->executeStatement('DELETE FROM film');
        $conn->executeStatement('DELETE FROM withdrawal_request');
        $conn->executeStatement('DELETE FROM studio');
        $conn->executeStatement('DELETE FROM "user"');
        $this->em->clear();
    }
}
