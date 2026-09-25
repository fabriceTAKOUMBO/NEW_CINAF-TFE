<?php

namespace App\Tests\Controller;

use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for Bunny.net streaming endpoints:
 *   GET /api/films/{id}/stream     → authenticated user required
 *   GET /api/episodes/{id}/stream  → authenticated user required
 *
 * Both endpoints must return {bunnyVideoId, libraryId} to the frontend player.
 * In the test environment, BUNNY_STREAM_LIBRARY_ID is set in .env.test.
 *
 * En français : pour chaque endpoint de lecture (film, épisode), vérifie le
 * 401 sans jeton, le 200 avec `{bunnyVideoId, libraryId}` non vides pour un
 * contenu publié, et le 404 pour un UUID inconnu. Aucun contenu non publié
 * n'est testé ; côté film, l'endpoint ne contrôle d'ailleurs pas le statut
 * (voir FilmController::streamInfo()).
 *
 * Run: php bin/phpunit tests/Controller/StreamEndpointsTest.php
 */
class StreamEndpointsTest extends ApiTestCase
{
    // -----------------------------------------------------------------------
    // Film streaming
    // -----------------------------------------------------------------------

    /**
     * GET /api/films/{id}/stream without auth → 401.
     */
    public function testFilmStreamRequiresAuth(): void
    {
        $client = static::createClient();
        $response = $this->getJson($client, '/api/films/' . $this->randomUuid() . '/stream');

        $this->assertSame(
            Response::HTTP_UNAUTHORIZED,
            $response->getStatusCode(),
            'Film stream endpoint must return 401 when not authenticated.'
        );
    }

    /**
     * GET /api/films/{id}/stream with ROLE_USER → 200 with {bunnyVideoId, libraryId}.
     */
    public function testFilmStreamReturnsBunnyId(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_USER');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $film = $this->seedPublishedFilm($em);

        $response = $this->getJson($client, '/api/films/' . $film->getId()->toRfc4122() . '/stream', $token);

        $body = $this->assertJsonResponse($response, Response::HTTP_OK);
        $this->assertArrayHasKey('bunnyVideoId', $body, 'Stream response must include "bunnyVideoId".');
        $this->assertArrayHasKey('libraryId', $body, 'Stream response must include "libraryId".');
        $this->assertNotEmpty($body['bunnyVideoId'], '"bunnyVideoId" must not be empty.');
        $this->assertNotEmpty($body['libraryId'], '"libraryId" must not be empty.');
    }

    /**
     * GET /api/films/{random-uuid}/stream with ROLE_USER → 404.
     */
    public function testFilmStreamReturns404ForUnknownFilm(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_USER');
        $response = $this->getJson($client, '/api/films/' . $this->randomUuid() . '/stream', $token);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // Episode streaming
    // -----------------------------------------------------------------------

    /**
     * GET /api/episodes/{id}/stream without auth → 401.
     */
    public function testEpisodeStreamRequiresAuth(): void
    {
        $client = static::createClient();
        $response = $this->getJson($client, '/api/episodes/' . $this->randomUuid() . '/stream');

        $this->assertSame(
            Response::HTTP_UNAUTHORIZED,
            $response->getStatusCode(),
            'Episode stream endpoint must return 401 when not authenticated.'
        );
    }

    /**
     * GET /api/episodes/{id}/stream with ROLE_USER → 200 with {bunnyVideoId, libraryId}.
     */
    public function testEpisodeStreamReturnsBunnyId(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_USER');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $episode = $this->seedPublishedEpisode($em);

        $response = $this->getJson($client, '/api/episodes/' . $episode->getId()->toRfc4122() . '/stream', $token);

        $body = $this->assertJsonResponse($response, Response::HTTP_OK);
        $this->assertArrayHasKey('bunnyVideoId', $body, 'Episode stream response must include "bunnyVideoId".');
        $this->assertArrayHasKey('libraryId', $body, 'Episode stream response must include "libraryId".');
        $this->assertNotEmpty($body['bunnyVideoId']);
        $this->assertNotEmpty($body['libraryId']);
    }

    /**
     * GET /api/episodes/{random-uuid}/stream with ROLE_USER → 404.
     */
    public function testEpisodeStreamReturns404ForUnknownEpisode(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_USER');
        $response = $this->getJson($client, '/api/episodes/' . $this->randomUuid() . '/stream', $token);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }
}
