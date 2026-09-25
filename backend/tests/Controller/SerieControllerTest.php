<?php

namespace App\Tests\Controller;

use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for /api/series and /api/episodes endpoints.
 *
 * Each test seeds its own data AFTER creating the HTTP client
 * (WebTestCase forbids booting the kernel twice).
 *
 * En français : tests fonctionnels de SerieController (liste, fiche,
 * recherche, saisons, épisodes d'une saison, PATCH admin) et du détail
 * d'épisode d'EpisodeController (401 sans jeton, 200, 404). Le masquage des
 * séries non PUBLISHED est couvert par PublicCatalogueStatusFilterTest.
 *
 * Run: php bin/phpunit tests/Controller/SerieControllerTest.php
 */
class SerieControllerTest extends ApiTestCase
{
    // -----------------------------------------------------------------------
    // Public listing tests
    // -----------------------------------------------------------------------

    /**
     * GET /api/series — public → 200 with paginated structure.
     */
    public function testListSeriesPublic(): void
    {
        $client = static::createClient();
        $response = $this->getJson($client, '/api/series');

        $body = $this->assertJsonResponse($response, Response::HTTP_OK);
        $this->assertPaginatedStructure($body);
    }

    /**
     * GET /api/series/{id} — existing published serie → 200 with complete structure.
     */
    public function testGetSerieById(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $serie = $this->seedPublishedSerie($em);

        $response = $this->getJson($client, '/api/series/' . $serie->getId()->toRfc4122());

        $body = $this->assertJsonResponse($response, Response::HTTP_OK);
        $this->assertArrayHasKey('id', $body);
        $this->assertArrayHasKey('title', $body);
        $this->assertArrayHasKey('year', $body);
    }

    /**
     * GET /api/series/{id} — random UUID → 404.
     */
    public function testGetSerieById404(): void
    {
        $client = static::createClient();
        $response = $this->getJson($client, '/api/series/' . $this->randomUuid());

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // Search & filters
    // -----------------------------------------------------------------------

    /**
     * GET /api/series/search?q=... → 200, every result matches the title query.
     */
    public function testSearchSeriesByTitle(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->seedPublishedSerie($em, ['title' => 'Sakho & Mangane']);

        $response = $this->getJson($client, '/api/series/search?q=Sakho');

        $body = $this->assertJsonResponse($response, Response::HTTP_OK);
        $this->assertPaginatedStructure($body);

        foreach ($body['data'] as $serie) {
            $this->assertStringContainsStringIgnoringCase('sakho', $serie['title']);
        }
    }

    /**
     * GET /api/series/search?genre=Drame → 200, all results carry the genre.
     *
     * Aucun genre n'existant en base de test, la recherche renvoie une liste
     * vide : en pratique, seuls le 200 et la structure paginée sont vérifiés.
     */
    public function testSearchSeriesByGenre(): void
    {
        $client = static::createClient();
        $response = $this->getJson($client, '/api/series/search?genre=Drame');

        $body = $this->assertJsonResponse($response, Response::HTTP_OK);
        $this->assertPaginatedStructure($body);

        foreach ($body['data'] as $serie) {
            $genreNames = array_column($serie['genres'] ?? [], 'name');
            $this->assertContains('Drame', $genreNames);
        }
    }

    /**
     * GET /api/series/search?year=2019 → 200, all results have year == 2019.
     */
    public function testSearchSeriesByYear(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->seedPublishedSerie($em, ['title' => 'Serie 2019', 'year' => 2019]);

        $response = $this->getJson($client, '/api/series/search?year=2019');

        $body = $this->assertJsonResponse($response, Response::HTTP_OK);
        $this->assertPaginatedStructure($body);

        foreach ($body['data'] as $serie) {
            $this->assertSame(2019, $serie['year']);
        }
    }

    // -----------------------------------------------------------------------
    // Seasons & Episodes (public)
    // -----------------------------------------------------------------------

    /**
     * GET /api/series/{id}/seasons → 200 with a list of seasons.
     *
     * La série seedée n'a aucune saison : la liste est vide, seul son type est vérifié.
     */
    public function testGetSeasons(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $serie = $this->seedPublishedSerie($em);

        $response = $this->getJson($client, '/api/series/' . $serie->getId()->toRfc4122() . '/seasons');

        $body = $this->assertJsonResponse($response, Response::HTTP_OK);
        $this->assertIsArray($body['data'] ?? $body, 'Seasons must be an array.');
    }

    /**
     * GET /api/series/{id}/seasons/1/episodes → 200 with a list of episodes.
     */
    public function testGetEpisodes(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $episode = $this->seedPublishedEpisode($em);
        $serie = $episode->getSeason()->getSerie();

        $response = $this->getJson($client, '/api/series/' . $serie->getId()->toRfc4122() . '/seasons/1/episodes');

        $body = $this->assertJsonResponse($response, Response::HTTP_OK);
        $this->assertIsArray($body);
        $this->assertCount(1, $body, 'Season 1 should expose exactly one seeded episode.');
    }

    // -----------------------------------------------------------------------
    // Episode detail (ROLE_USER required)
    // -----------------------------------------------------------------------

    /**
     * GET /api/episodes/{id} without auth → 401.
     */
    public function testGetEpisodeRequiresAuth(): void
    {
        $client = static::createClient();
        $response = $this->getJson($client, '/api/episodes/' . $this->randomUuid());

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    /**
     * GET /api/episodes/{id} with valid auth → 200 with bunnyVideoId in payload.
     */
    public function testGetEpisodeAuthenticated(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_USER');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $episode = $this->seedPublishedEpisode($em);

        $response = $this->getJson($client, '/api/episodes/' . $episode->getId()->toRfc4122(), $token);

        $body = $this->assertJsonResponse($response, Response::HTTP_OK);
        $this->assertArrayHasKey('id', $body);
        $this->assertArrayHasKey('bunnyVideoId', $body, 'Episode payload must include bunnyVideoId.');
    }

    /**
     * GET /api/episodes/{id} with ROLE_USER but non-existent UUID → 404.
     */
    public function testGetEpisode404(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_USER');
        $response = $this->getJson($client, '/api/episodes/' . $this->randomUuid(), $token);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    // NB : l'endpoint admin POST /api/series a été retiré (2026-06-03) — la
    // création de séries se fait côté studio (voir StudioSerieControllerTest).

    // -----------------------------------------------------------------------
    // Access control — PATCH /api/series/{id}
    // -----------------------------------------------------------------------

    /**
     * PATCH /api/series/{id} with an invalid token → 401.
     */
    public function testPatchSerieRequiresAuth(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $serie = $this->seedPublishedSerie($em);

        $response = $this->patchJson($client, '/api/series/' . $serie->getId()->toRfc4122(), ['title' => 'Updated'], 'bad-token');

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    /**
     * PATCH /api/series/{id} with ROLE_ADMIN → 200 and the field is updated.
     */
    public function testPatchSerieWithAdmin(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $serie = $this->seedPublishedSerie($em);

        $response = $this->patchJson($client, '/api/series/' . $serie->getId()->toRfc4122(), ['title' => 'Titre Serie Modifie'], $token);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertSame('Titre Serie Modifie', $body['title']);
    }
}
