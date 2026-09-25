<?php

namespace App\Tests\Controller;

use App\Entity\Film;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for /api/films endpoints.
 *
 * Each test seeds its own data through seedPublishedFilm() AFTER creating the
 * HTTP client (WebTestCase forbids booting the kernel twice).
 *
 * En français : tests fonctionnels de FilmController — liste, fiche,
 * recherche (titre, genre, année), listes spéciales (featured, trending, new),
 * contrôle d'accès de PATCH / DELETE (401 sans jeton valide, 403 pour
 * ROLE_USER, succès pour ROLE_ADMIN) et compteur de vues. Le masquage des
 * films non PUBLISHED est couvert par PublicCatalogueStatusFilterTest.
 *
 * Run: php bin/phpunit tests/Controller/FilmControllerTest.php
 */
class FilmControllerTest extends ApiTestCase
{
    // -----------------------------------------------------------------------
    // Public listing tests
    // -----------------------------------------------------------------------

    /**
     * GET /api/films — public, no auth required → 200 with paginated structure.
     */
    public function testListFilmsPublic(): void
    {
        $client = static::createClient();
        $response = $this->getJson($client, '/api/films');

        $body = $this->assertJsonResponse($response, Response::HTTP_OK);
        $this->assertPaginatedStructure($body);
        $this->assertIsInt($body['total']);
        $this->assertGreaterThanOrEqual(0, $body['total']);
        $this->assertSame(1, $body['page']);
        $this->assertGreaterThan(0, $body['limit']);
    }

    /**
     * GET /api/films/{id} — existing published film → 200 with complete structure.
     */
    public function testGetFilmById(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $film = $this->seedPublishedFilm($em);

        $response = $this->getJson($client, '/api/films/' . $film->getId()->toRfc4122());

        $body = $this->assertJsonResponse($response, Response::HTTP_OK);
        $this->assertArrayHasKey('id', $body);
        $this->assertArrayHasKey('title', $body);
        $this->assertArrayHasKey('year', $body);
        $this->assertArrayHasKey('views', $body);
    }

    /**
     * GET /api/films/{id} — random UUID → 404.
     */
    public function testGetFilmById404(): void
    {
        $client = static::createClient();
        $response = $this->getJson($client, '/api/films/' . $this->randomUuid());

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // Search & filter tests
    // -----------------------------------------------------------------------

    /**
     * GET /api/films/search?q=... → 200, every result matches the title query.
     */
    public function testSearchByTitle(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->seedPublishedFilm($em, ['title' => 'Atlantique']);

        $response = $this->getJson($client, '/api/films/search?q=Atlantique');

        $body = $this->assertJsonResponse($response, Response::HTTP_OK);
        $this->assertPaginatedStructure($body);

        foreach ($body['data'] as $film) {
            $this->assertStringContainsStringIgnoringCase('atlantique', $film['title']);
        }
    }

    /**
     * GET /api/films/search?genre=Action → 200, all results carry the genre.
     *
     * Aucun genre n'existant en base de test, la recherche renvoie une liste
     * vide : en pratique, seuls le 200 et la structure paginée sont vérifiés.
     */
    public function testSearchByGenre(): void
    {
        $client = static::createClient();
        $response = $this->getJson($client, '/api/films/search?genre=Action');

        $body = $this->assertJsonResponse($response, Response::HTTP_OK);
        $this->assertPaginatedStructure($body);

        foreach ($body['data'] as $film) {
            $genreNames = array_column($film['genres'] ?? [], 'name');
            $this->assertContains('Action', $genreNames, 'Film returned without the requested genre.');
        }
    }

    /**
     * GET /api/films/search?year=2024 → 200, all results have year == 2024.
     */
    public function testSearchByYear(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->seedPublishedFilm($em, ['title' => 'Sortie 2024', 'year' => 2024]);

        $response = $this->getJson($client, '/api/films/search?year=2024');

        $body = $this->assertJsonResponse($response, Response::HTTP_OK);
        $this->assertPaginatedStructure($body);

        foreach ($body['data'] as $film) {
            $this->assertSame(2024, $film['year'], 'Film returned for year filter does not match 2024.');
        }
    }

    // -----------------------------------------------------------------------
    // Special listing endpoints
    // -----------------------------------------------------------------------

    /**
     * GET /api/films/featured → 200 with an array payload.
     *
     * Aucune mise en avant n'existe en base de test : la liste est vide.
     */
    public function testFeatured(): void
    {
        $client = static::createClient();
        $response = $this->getJson($client, '/api/films/featured');

        $body = $this->assertJsonResponse($response, Response::HTTP_OK);
        $this->assertIsArray($body['data'] ?? $body);
    }

    /**
     * GET /api/films/trending → 200 with an array payload (sorted by views desc).
     *
     * Le tri annoncé est celui de l'endpoint : le test ne vérifie que le type tableau.
     */
    public function testTrending(): void
    {
        $client = static::createClient();
        $response = $this->getJson($client, '/api/films/trending');

        $body = $this->assertJsonResponse($response, Response::HTTP_OK);
        $this->assertIsArray($body['data'] ?? $body);
    }

    /**
     * GET /api/films/new → 200 with an array payload (sorted by createdAt desc).
     *
     * Comme pour trending, le tri n'est pas vérifié, seulement le type tableau.
     */
    public function testNew(): void
    {
        $client = static::createClient();
        $response = $this->getJson($client, '/api/films/new');

        $body = $this->assertJsonResponse($response, Response::HTTP_OK);
        $this->assertIsArray($body['data'] ?? $body);
    }

    // NB : l'endpoint admin POST /api/films a été retiré (2026-06-03) — la
    // création de films se fait côté studio (voir StudioFilmControllerTest).

    // -----------------------------------------------------------------------
    // Access control — PATCH /api/films/{id}
    // -----------------------------------------------------------------------

    /**
     * PATCH /api/films/{id} with an invalid token → 401.
     */
    public function testPatchFilmRequiresAuth(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $film = $this->seedPublishedFilm($em);

        $response = $this->patchJson($client, '/api/films/' . $film->getId()->toRfc4122(), ['title' => 'Updated'], 'invalid-token');

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    /**
     * PATCH /api/films/{id} with ROLE_USER → 403.
     */
    public function testPatchFilmForbiddenForRoleUser(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_USER');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $film = $this->seedPublishedFilm($em);

        $response = $this->patchJson($client, '/api/films/' . $film->getId()->toRfc4122(), ['title' => 'Updated'], $token);

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    /**
     * PATCH /api/films/{id} with ROLE_ADMIN → 200 and the field is updated.
     */
    public function testPatchFilmWithAdmin(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $film = $this->seedPublishedFilm($em);

        $response = $this->patchJson($client, '/api/films/' . $film->getId()->toRfc4122(), ['title' => 'Titre Modifie'], $token);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertSame('Titre Modifie', $body['title']);
    }

    // -----------------------------------------------------------------------
    // Access control — DELETE /api/films/{id}
    // -----------------------------------------------------------------------

    /**
     * DELETE /api/films/{id} without auth → 401.
     */
    public function testDeleteFilmRequiresAuth(): void
    {
        $client = static::createClient();
        $headers = ['HTTP_ACCEPT' => 'application/json'];
        $client->request('DELETE', '/api/films/' . $this->randomUuid(), [], [], $headers);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
    }

    /**
     * DELETE /api/films/{id} with ROLE_USER → 403.
     */
    public function testDeleteFilmForbiddenForRoleUser(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_USER');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $film = $this->seedPublishedFilm($em);

        $response = $this->deleteJson($client, '/api/films/' . $film->getId()->toRfc4122(), $token);

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    /**
     * DELETE /api/films/{id} with ROLE_ADMIN → 204, then the film is gone.
     *
     * Le film est seedé directement (avec son studio) car l'endpoint de création
     * admin POST /api/films a été retiré (création côté studio).
     */
    public function testDeleteFilmWithAdmin(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $film = $this->seedPublishedFilm($em);
        $filmId = $film->getId()->toRfc4122();

        $response = $this->deleteJson($client, '/api/films/' . $filmId, $token);
        $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());

        $getResponse = $this->getJson($client, '/api/films/' . $filmId);
        $this->assertSame(Response::HTTP_NOT_FOUND, $getResponse->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // View counter
    // -----------------------------------------------------------------------

    /**
     * POST /api/films/{id}/view without auth → 401.
     */
    public function testIncrementViewRequiresAuth(): void
    {
        $client = static::createClient();
        $response = $this->postJson($client, '/api/films/' . $this->randomUuid() . '/view', []);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    /**
     * POST /api/films/{id}/view with ROLE_USER → 204 and the DB counter is incremented.
     */
    public function testIncrementView(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_USER');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $film = $this->seedPublishedFilm($em, ['views' => 5]);
        $filmId = $film->getId()->toRfc4122();
        $initialViews = $film->getViews();

        $response = $this->postJson($client, '/api/films/' . $filmId . '/view', [], $token);
        $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());

        // Re-fetch from DB (clear identity map first).
        $em->clear();
        $filmAfter = $em->getRepository(Film::class)->find($filmId);
        $this->assertSame($initialViews + 1, $filmAfter->getViews(), 'View counter was not incremented in DB.');
    }
}
