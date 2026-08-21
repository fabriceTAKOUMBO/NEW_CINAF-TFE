<?php

namespace App\Tests\Controller;

use App\Entity\Film;
use App\Entity\FilmPart;
use App\Entity\Serie;
use App\Entity\Studio;
use App\Entity\User;
use App\Service\BunnyCatalogueService;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests fonctionnels du contrôleur public /api/catalogue/discover après bascule
 * vers la source DB (Phase F — Agent 5).
 *
 * Force le feature flag `CATALOGUE_SOURCE=db` pour valider que :
 *  - seules les œuvres status='PUBLISHED' sont exposées (pas DRAFT, pas WITHDRAWN)
 *  - le contrat DiscoverWorkSummary / DiscoverWork est préservé
 *  - can-play retourne 404 pour un slug inexistant en DB
 */
class CatalogueDiscoverControllerTest extends ApiTestCase
{
    protected function setUp(): void
    {
        // Force la source DB pour ce test (override le default 'bunny' du .env).
        $_ENV['CATALOGUE_SOURCE'] = 'db';
        $_SERVER['CATALOGUE_SOURCE'] = 'db';
        putenv('CATALOGUE_SOURCE=db');
        parent::setUp();
    }

    public function test_db_source_lists_only_published(): void
    {
        $client = static::createClient();
        $this->cleanCatalogueTables();
        $studio = $this->ensureStudio();

        $this->createFilm($studio, 'Published Film', Film::STATUS_PUBLISHED, 'pub-film-test');
        $this->createFilm($studio, 'Draft Film', Film::STATUS_DRAFT, 'draft-film-test');
        $this->createFilm($studio, 'Withdrawn Film', Film::STATUS_WITHDRAWN, 'wd-film-test');

        $response = $this->getJson($client, '/api/catalogue/discover?limit=50');
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $titles = array_column($body['data'], 'title');
        $this->assertContains('Published Film', $titles);
        $this->assertNotContains('Draft Film', $titles);
        $this->assertNotContains('Withdrawn Film', $titles);
    }

    public function test_db_source_detail_published_returns_data(): void
    {
        $client = static::createClient();
        $this->cleanCatalogueTables();
        $studio = $this->ensureStudio();
        $this->createFilm($studio, 'Detail Film', Film::STATUS_PUBLISHED, 'detail-film-test');

        $response = $this->getJson($client, '/api/catalogue/discover/detail-film-test');
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $this->assertSame('detail-film-test', $body['slug']);
        $this->assertSame('Detail Film', $body['title']);
        $this->assertSame('film', $body['kind']);
        $this->assertArrayHasKey('seasons', $body);
        $this->assertIsArray($body['seasons']);
    }

    public function test_db_source_detail_draft_returns_404(): void
    {
        $client = static::createClient();
        $this->cleanCatalogueTables();
        $studio = $this->ensureStudio();
        $this->createFilm($studio, 'Hidden Draft', Film::STATUS_DRAFT, 'hidden-draft');

        $response = $this->getJson($client, '/api/catalogue/discover/hidden-draft');
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function test_db_source_detail_withdrawn_returns_404(): void
    {
        $client = static::createClient();
        $this->cleanCatalogueTables();
        $studio = $this->ensureStudio();
        $this->createFilm($studio, 'Hidden WD', Film::STATUS_WITHDRAWN, 'hidden-wd');

        $response = $this->getJson($client, '/api/catalogue/discover/hidden-wd');
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function test_db_source_detail_serie_published(): void
    {
        $client = static::createClient();
        $this->cleanCatalogueTables();
        $studio = $this->ensureStudio();
        $this->createSerie($studio, 'My Serie Test', Serie::STATUS_PUBLISHED, 'my-serie-test');

        $response = $this->getJson($client, '/api/catalogue/discover/my-serie-test');
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $this->assertSame('serie', $body['kind']);
        $this->assertSame('my-serie-test', $body['slug']);
    }

    public function test_can_play_404_for_unknown_slug(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_USER');
        $this->cleanCatalogueTables();
        $response = $this->getJson($client, '/api/catalogue/discover/slug-inexistant/can-play', $token);
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // Tests dissociation films / séries (2026-08-21)
    // — mapFilmToDiscover construit désormais la réponse depuis la BASE :
    // une entrée par `FilmPart`. Le contournement 2026-05-22, qui rescannait
    // Bunny en live pour les œuvres importées avec le seul path racine, est
    // supprimé : l'import écrit maintenant le chemin vidéo réel de chaque
    // partie, et les vraies séries ne sont plus classées en films.
    // -----------------------------------------------------------------------

    public function test_db_source_film_with_parts_returns_one_episode_per_part(): void
    {
        $client = static::createClient();
        $this->cleanCatalogueTables();
        $studio = $this->ensureStudio();

        // Film livré en 3 morceaux sur Bunny, tel qu'importé par
        // `app:catalogue:import-bunny` : `bunnyVideoId` = 1re partie, et une
        // FilmPart par morceau avec son propre chemin.
        $paths = [
            'FILMS/GUCCI_BROTHERS/PART1/GUCCI_BROTHERS_PART1',
            'FILMS/GUCCI_BROTHERS/PART2/GUCCI_BROTHERS_PART2',
            'FILMS/GUCCI_BROTHERS/PART3/GUCCI_BROTHERS_PART3',
        ];
        $film = $this->createFilmWithBunnyPath(
            $studio,
            'GUCCI_BROTHERS',
            Film::STATUS_PUBLISHED,
            'gucci-brothers',
            $paths[0],
        );
        $this->addFilmParts($film, $paths);

        $response = $this->getJson($client, '/api/catalogue/discover/gucci-brothers');
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $this->assertSame('film', $body['kind']);
        $this->assertCount(1, $body['seasons']);
        $this->assertSame('principale', $body['seasons'][0]['slug']);
        $this->assertCount(3, $body['seasons'][0]['episodes']);
        // Slugs distincts (pas écrasés à `principal` puisqu'il y a >1 partie).
        $slugs = array_column($body['seasons'][0]['episodes'], 'slug');
        $this->assertSame(['partie-1', 'partie-2', 'partie-3'], $slugs);
        // Chaque hlsUrl pointe vers le master de la bonne partie, pas la racine.
        foreach ($body['seasons'][0]['episodes'] as $i => $ep) {
            $this->assertStringEndsWith('/master.m3u8', $ep['hlsUrl']);
            $this->assertStringContainsString('PART' . ($i + 1), $ep['hlsUrl']);
        }
    }

    public function test_db_source_film_mono_episode_keeps_principal_slug(): void
    {
        $client = static::createClient();
        $this->cleanCatalogueTables();
        $studio = $this->ensureStudio();
        $this->createFilmWithBunnyPath($studio, 'Vrai Film Flat', Film::STATUS_PUBLISHED, 'vrai-film-flat', 'VRAI_FILM');

        // Mock Bunny : œuvre flat avec un SEUL épisode (vrai film mono).
        $this->overrideBunnyCatalogue([
            'slug' => 'vrai-film-flat',
            'title' => 'Vrai Film Flat',
            'kind' => 'film',
            'seasons' => [[
                'slug' => 'principale',
                'name' => 'Œuvre',
                'episodes' => [[
                    'slug' => 'vrai-film',
                    'name' => 'VRAI_FILM',
                    'hlsUrl' => 'https://cdn.example/VRAI_FILM/master.m3u8',
                    'mp4Url' => null,
                ]],
            ]],
        ]);

        $response = $this->getJson($client, '/api/catalogue/discover/vrai-film-flat');
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $this->assertCount(1, $body['seasons']);
        $this->assertCount(1, $body['seasons'][0]['episodes']);
        // Rétro-compat URL : le slug doit être forcé à `principal` pour que
        // les liens existants `?ep=principal&s=principale` continuent à matcher.
        $this->assertSame('principal', $body['seasons'][0]['episodes'][0]['slug']);
    }

    public function test_db_source_film_fallback_when_bunny_throws(): void
    {
        $client = static::createClient();
        $this->cleanCatalogueTables();
        $studio = $this->ensureStudio();
        $this->createFilmWithBunnyPath($studio, 'Bunny Down', Film::STATUS_PUBLISHED, 'bunny-down', 'BUNNY_DOWN');

        // Mock Bunny : lève une exception → controller doit retomber sur
        // l'ancien comportement (1 saison `principale`, 1 épisode `principal`,
        // hlsUrl construite depuis `bunnyVideoId`).
        $mock = $this->createMock(BunnyCatalogueService::class);
        $mock->method('getWork')->willThrowException(new \RuntimeException('Bunny upstream HS'));
        static::getContainer()->set(BunnyCatalogueService::class, $mock);

        $response = $this->getJson($client, '/api/catalogue/discover/bunny-down');
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $this->assertCount(1, $body['seasons']);
        $this->assertSame('principale', $body['seasons'][0]['slug']);
        $this->assertCount(1, $body['seasons'][0]['episodes']);
        $this->assertSame('principal', $body['seasons'][0]['episodes'][0]['slug']);
        // Le fallback construit hlsUrl à partir de `Film::bunnyVideoId` = 'BUNNY_DOWN'.
        $this->assertStringContainsString('BUNNY_DOWN', $body['seasons'][0]['episodes'][0]['hlsUrl']);
        $this->assertStringEndsWith('/master.m3u8', $body['seasons'][0]['episodes'][0]['hlsUrl']);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function ensureStudio(): Studio
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $existing = $em->getRepository(Studio::class)->findOneBy(['slug' => 'discover-test-studio']);
        if ($existing !== null) {
            return $existing;
        }

        $hasher = static::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);
        $owner = new User();
        $owner->setEmail('discover-test-' . bin2hex(random_bytes(3)) . '@cinaf-test.com');
        $owner->setFirstName('Owner');
        $owner->setLastName('Discover');
        $owner->setRoles(['ROLE_CREATEUR']);
        $owner->setIsVerified(true);
        $owner->setPassword($hasher->hashPassword($owner, 'Password123!'));
        $em->persist($owner);

        $studio = new Studio();
        $studio->setName('Discover Test Studio');
        $studio->setSlug('discover-test-studio');
        $studio->setOwner($owner);
        $studio->setBunnyFolder('studios/discover-test-studio-' . bin2hex(random_bytes(2)) . '/');
        $studio->setIsActive(true);
        $em->persist($studio);
        $em->flush();
        return $studio;
    }

    private function createFilm(Studio $studio, string $title, string $status, string $slug): Film
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $film = new Film();
        $film->setTitle($title);
        $film->setSlug($slug);
        $film->setSynopsis('Synopsis test ' . $title);
        $film->setYear(2024);
        $film->setDuration(90);
        $film->setStudio($studio);
        $film->setStatus($status);
        if ($status === Film::STATUS_PUBLISHED) {
            $film->setPublishedAt(new \DateTimeImmutable());
        }
        $em->persist($film);
        $em->flush();
        return $film;
    }

    /**
     * Variante de createFilm() qui renseigne `bunnyVideoId` — utile pour les
     * tests du fix 2026-05-22 (fallback contrôleur sur Film::bunnyVideoId
     * quand Bunny ne répond pas).
     */
    private function createFilmWithBunnyPath(Studio $studio, string $title, string $status, string $slug, string $bunnyPath): Film
    {
        $film = $this->createFilm($studio, $title, $status, $slug);
        $film->setBunnyVideoId($bunnyPath);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->flush();
        return $film;
    }

    /**
     * Attache N parties vidéo au film, dans l'ordre fourni — reproduit ce que
     * `app:catalogue:import-bunny` écrit pour un film livré en plusieurs
     * morceaux sur Bunny.
     *
     * @param list<string> $bunnyPaths
     */
    private function addFilmParts(Film $film, array $bunnyPaths): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $number = 1;
        foreach ($bunnyPaths as $path) {
            $part = new FilmPart();
            $part->setNumber($number);
            $part->setTitle(basename($path));
            $part->setBunnyVideoId($path);
            $film->addPart($part);
            $em->persist($part);
            $number++;
        }
        $em->flush();
    }

    /**
     * Remplace dans le container test le service `BunnyCatalogueService` par
     * un mock qui retourne la structure d'œuvre fournie en argument lors de
     * `getWork()`. Tous les autres appels du mock retournent null/0 par défaut.
     *
     * Pattern testé : le contrôleur récupère le service depuis le container
     * au moment du request, donc remplacer le service AVANT l'appel HTTP
     * suffit (pas besoin de redémarrer le kernel).
     *
     * @param array{slug:string,title:string,kind:string,seasons:array} $bunnyWork
     */
    private function overrideBunnyCatalogue(array $bunnyWork): void
    {
        $mock = $this->createMock(BunnyCatalogueService::class);
        $mock->method('getWork')->willReturn($bunnyWork);
        static::getContainer()->set(BunnyCatalogueService::class, $mock);
    }

    private function createSerie(Studio $studio, string $title, string $status, string $slug): Serie
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $serie = new Serie();
        $serie->setTitle($title);
        $serie->setSlug($slug);
        $serie->setSynopsis('Synopsis test ' . $title);
        $serie->setYear(2024);
        $serie->setStudio($studio);
        $serie->setStatus($status);
        if ($status === Serie::STATUS_PUBLISHED) {
            $serie->setPublishedAt(new \DateTimeImmutable());
        }
        $em->persist($serie);
        $em->flush();
        return $serie;
    }

    private function cleanCatalogueTables(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $conn = $em->getConnection();
        $conn->executeStatement('DELETE FROM episode');
        $conn->executeStatement('DELETE FROM season');
        $conn->executeStatement('DELETE FROM serie');
        $conn->executeStatement('DELETE FROM film_part');
        $conn->executeStatement('DELETE FROM film');
        $em->clear();
    }
}
