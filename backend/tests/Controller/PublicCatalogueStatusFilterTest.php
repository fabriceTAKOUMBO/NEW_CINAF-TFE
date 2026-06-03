<?php

namespace App\Tests\Controller;

use App\Entity\Film;
use App\Entity\Serie;
use App\Entity\Studio;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Décision métier (chef de projet, 2026-05-13) :
 * "Seuls les contenus PUBLISHED apparaissent dans le catalogue public.
 *  DRAFT et WITHDRAWN sont totalement invisibles pour les non-propriétaires,
 *  y compris en accédant à leur URL directe — réponse 404, jamais 403."
 *
 * Ce test couvre les endpoints publics /api/films, /api/series et /api/episodes
 * (les endpoints studio /api/studio/* et admin /api/admin/* sont hors périmètre
 * et restent inchangés — voir tests/Studio et tests/Admin pour leur couverture).
 */
class PublicCatalogueStatusFilterTest extends ApiTestCase
{
    // -----------------------------------------------------------------------
    // /api/films — listings et recherche n'exposent que PUBLISHED
    // -----------------------------------------------------------------------

    public function testListFilmsHidesDraftAndWithdrawn(): void
    {
        $client = static::createClient();
        $this->cleanCatalogueTables();
        $studio = $this->ensureStudio();

        $this->createFilm($studio, 'Film Published Visible', Film::STATUS_PUBLISHED, 'film-pub-visible');
        $this->createFilm($studio, 'Film Draft Hidden', Film::STATUS_DRAFT, 'film-draft-hidden');
        $this->createFilm($studio, 'Film Withdrawn Hidden', Film::STATUS_WITHDRAWN, 'film-wd-hidden');

        $response = $this->getJson($client, '/api/films?limit=100');
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $titles = array_column($body['data'], 'title');
        $this->assertContains('Film Published Visible', $titles);
        $this->assertNotContains('Film Draft Hidden', $titles);
        $this->assertNotContains('Film Withdrawn Hidden', $titles);
        $this->assertSame(1, $body['total'], 'Le total ne doit compter que les films PUBLISHED');
    }

    public function testSearchFilmsHidesDraftAndWithdrawn(): void
    {
        $client = static::createClient();
        $this->cleanCatalogueTables();
        $studio = $this->ensureStudio();

        $this->createFilm($studio, 'Mystère Atlantique Publié', Film::STATUS_PUBLISHED, 'mystere-pub');
        $this->createFilm($studio, 'Mystère Atlantique Brouillon', Film::STATUS_DRAFT, 'mystere-draft');
        $this->createFilm($studio, 'Mystère Atlantique Retiré', Film::STATUS_WITHDRAWN, 'mystere-wd');

        $response = $this->getJson($client, '/api/films/search?q=Mystère');
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $titles = array_column($body['data'], 'title');
        $this->assertContains('Mystère Atlantique Publié', $titles);
        $this->assertNotContains('Mystère Atlantique Brouillon', $titles);
        $this->assertNotContains('Mystère Atlantique Retiré', $titles);
    }

    public function testGetFilmDraftReturns404NotFound(): void
    {
        $client = static::createClient();
        $this->cleanCatalogueTables();
        $studio = $this->ensureStudio();
        $film = $this->createFilm($studio, 'Brouillon Caché', Film::STATUS_DRAFT, 'brouillon-cache');

        $response = $this->getJson($client, '/api/films/' . $film->getId()->toRfc4122());

        // 404 — surtout pas 403 (pas de trace de l'existence d'un brouillon).
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertNotSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testGetFilmWithdrawnReturns404NotFound(): void
    {
        $client = static::createClient();
        $this->cleanCatalogueTables();
        $studio = $this->ensureStudio();
        $film = $this->createFilm($studio, 'Retiré Caché', Film::STATUS_WITHDRAWN, 'retire-cache');

        $response = $this->getJson($client, '/api/films/' . $film->getId()->toRfc4122());

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testGetFilmPublishedReturns200(): void
    {
        $client = static::createClient();
        $this->cleanCatalogueTables();
        $studio = $this->ensureStudio();
        $film = $this->createFilm($studio, 'Visible Publié', Film::STATUS_PUBLISHED, 'visible-pub');

        $response = $this->getJson($client, '/api/films/' . $film->getId()->toRfc4122());
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $this->assertSame('Visible Publié', $body['title']);
    }

    // -----------------------------------------------------------------------
    // /api/series — listings et recherche n'exposent que PUBLISHED
    // -----------------------------------------------------------------------

    public function testListSeriesHidesDraftAndWithdrawn(): void
    {
        $client = static::createClient();
        $this->cleanCatalogueTables();
        $studio = $this->ensureStudio();

        $this->createSerie($studio, 'Série Publiée Visible', Serie::STATUS_PUBLISHED, 'serie-pub-vis');
        $this->createSerie($studio, 'Série Brouillon Cachée', Serie::STATUS_DRAFT, 'serie-draft-hid');
        $this->createSerie($studio, 'Série Retirée Cachée', Serie::STATUS_WITHDRAWN, 'serie-wd-hid');

        $response = $this->getJson($client, '/api/series?limit=100');
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $titles = array_column($body['data'], 'title');
        $this->assertContains('Série Publiée Visible', $titles);
        $this->assertNotContains('Série Brouillon Cachée', $titles);
        $this->assertNotContains('Série Retirée Cachée', $titles);
        $this->assertSame(1, $body['total']);
    }

    public function testSearchSeriesHidesDraftAndWithdrawn(): void
    {
        $client = static::createClient();
        $this->cleanCatalogueTables();
        $studio = $this->ensureStudio();

        $this->createSerie($studio, 'Sentinelle Publiée', Serie::STATUS_PUBLISHED, 'sentinelle-pub');
        $this->createSerie($studio, 'Sentinelle Brouillon', Serie::STATUS_DRAFT, 'sentinelle-draft');

        $response = $this->getJson($client, '/api/series/search?q=Sentinelle');
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $titles = array_column($body['data'], 'title');
        $this->assertContains('Sentinelle Publiée', $titles);
        $this->assertNotContains('Sentinelle Brouillon', $titles);
    }

    public function testGetSerieDraftReturns404NotFound(): void
    {
        $client = static::createClient();
        $this->cleanCatalogueTables();
        $studio = $this->ensureStudio();
        $serie = $this->createSerie($studio, 'Série Brouillon', Serie::STATUS_DRAFT, 'serie-brouillon');

        $response = $this->getJson($client, '/api/series/' . $serie->getId()->toRfc4122());

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertNotSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testGetSerieWithdrawnReturns404NotFound(): void
    {
        $client = static::createClient();
        $this->cleanCatalogueTables();
        $studio = $this->ensureStudio();
        $serie = $this->createSerie($studio, 'Série Retirée', Serie::STATUS_WITHDRAWN, 'serie-retiree');

        $response = $this->getJson($client, '/api/series/' . $serie->getId()->toRfc4122());

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testGetSerieSeasonsOnDraftReturns404(): void
    {
        $client = static::createClient();
        $this->cleanCatalogueTables();
        $studio = $this->ensureStudio();
        $serie = $this->createSerie($studio, 'Série Brouillon Saisons', Serie::STATUS_DRAFT, 'serie-brouillon-saisons');

        $response = $this->getJson($client, '/api/series/' . $serie->getId()->toRfc4122() . '/seasons');

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // /api/catalogue/discover — can-play 404 sur DRAFT/WITHDRAWN
    // -----------------------------------------------------------------------

    public function testCanPlayOnDraftReturns404(): void
    {
        $_ENV['CATALOGUE_SOURCE'] = 'db';
        $_SERVER['CATALOGUE_SOURCE'] = 'db';
        putenv('CATALOGUE_SOURCE=db');

        [$client, $token] = $this->createAuthenticatedClient('ROLE_USER');
        $this->cleanCatalogueTables();
        $studio = $this->ensureStudio();
        $this->createFilm($studio, 'Brouillon Can Play', Film::STATUS_DRAFT, 'brouillon-canplay');

        $response = $this->getJson($client, '/api/catalogue/discover/brouillon-canplay/can-play', $token);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testCanPlayOnWithdrawnReturns404(): void
    {
        $_ENV['CATALOGUE_SOURCE'] = 'db';
        $_SERVER['CATALOGUE_SOURCE'] = 'db';
        putenv('CATALOGUE_SOURCE=db');

        [$client, $token] = $this->createAuthenticatedClient('ROLE_USER');
        $this->cleanCatalogueTables();
        $studio = $this->ensureStudio();
        $this->createFilm($studio, 'Retiré Can Play', Film::STATUS_WITHDRAWN, 'retire-canplay');

        $response = $this->getJson($client, '/api/catalogue/discover/retire-canplay/can-play', $token);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // Helpers (copiés du pattern CatalogueDiscoverControllerTest)
    // -----------------------------------------------------------------------

    private function ensureStudio(): Studio
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $existing = $em->getRepository(Studio::class)->findOneBy(['slug' => 'public-status-filter-studio']);
        if ($existing !== null) {
            return $existing;
        }

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $owner = new User();
        $owner->setEmail('status-filter-' . bin2hex(random_bytes(3)) . '@cinaf-test.com');
        $owner->setFirstName('Owner');
        $owner->setLastName('StatusFilter');
        $owner->setRoles(['ROLE_CREATEUR']);
        $owner->setIsVerified(true);
        $owner->setPassword($hasher->hashPassword($owner, 'Password123!'));
        $em->persist($owner);

        $studio = new Studio();
        $studio->setName('Public Status Filter Studio');
        $studio->setSlug('public-status-filter-studio');
        $studio->setOwner($owner);
        $studio->setBunnyFolder('studios/public-status-filter-studio-' . bin2hex(random_bytes(2)) . '/');
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
        $conn->executeStatement('DELETE FROM film');
        $em->clear();
    }
}
