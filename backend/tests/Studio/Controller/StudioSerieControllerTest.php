<?php

namespace App\Tests\Studio\Controller;

use App\Tests\Studio\Support\StudioTestTrait;
use App\Tests\Support\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests fonctionnels pour /api/studio/series — focus sur les ajouts
 * de 2026-05-13 :
 *   - 409 sur création d'épisode avec un `number` déjà pris dans la saison
 *     (pré-check unicité ajouté à StudioSerieController).
 *   - GET /api/studio/series/{id} doit inclure les épisodes dans chaque
 *     saison (bug fix : `Serie::toArray($expand=true)` ne propageait pas
 *     `withEpisodes=true` à `Season::toArray()`, donc la liste d'épisodes
 *     était toujours vide côté frontend studio).
 *
 * Important : `static::createClient()` DOIT être appelé avant tout accès
 * au container (sinon LogicException "Booting the kernel before calling
 * createClient()" — gotcha connu des tests Studio).
 */
class StudioSerieControllerTest extends ApiTestCase
{
    use StudioTestTrait;

    private function createSerieDraft(KernelBrowser $client, string $token): string
    {
        $response = $this->postJson($client, '/api/studio/series', [
            'title'    => 'Ma Série Test',
            'synopsis' => 'Synopsis test',
            'year'     => 2026,
        ], $token);

        $body = $this->assertJsonResponse($response, Response::HTTP_CREATED);
        return $body['id'];
    }

    private function createSeason(KernelBrowser $client, string $serieId, string $token, int $number = 1): string
    {
        $response = $this->postJson(
            $client,
            sprintf('/api/studio/series/%s/seasons', $serieId),
            ['number' => $number, 'title' => 'Saison ' . $number],
            $token,
        );
        $body = $this->assertJsonResponse($response, Response::HTTP_CREATED);
        return $body['id'];
    }

    // ─── 409 unicité épisode ──────────────────────────────────────

    public function testCreateEpisodeWithDuplicateNumberReturns409(): void
    {
        $client = static::createClient();
        [, , $token] = $this->createCreatorWithStudio();
        $serieId = $this->createSerieDraft($client, $token);
        $seasonId = $this->createSeason($client, $serieId, $token);

        // 1er épisode #1 → 201
        $first = $this->postJson(
            $client,
            sprintf('/api/studio/series/%s/seasons/%s/episodes', $serieId, $seasonId),
            ['number' => 1, 'title' => 'Pilote'],
            $token,
        );
        $this->assertJsonResponse($first, Response::HTTP_CREATED);

        // 2e épisode avec même number → 409 (pré-check unicité, pas 500)
        $second = $this->postJson(
            $client,
            sprintf('/api/studio/series/%s/seasons/%s/episodes', $serieId, $seasonId),
            ['number' => 1, 'title' => 'Doublon'],
            $token,
        );
        $body = $this->assertJsonResponse($second, Response::HTTP_CONFLICT);
        $this->assertStringContainsString('existe déjà', $body['message']);
    }

    public function testCreateEpisodeWithZeroNumberReturns400(): void
    {
        $client = static::createClient();
        [, , $token] = $this->createCreatorWithStudio();
        $serieId = $this->createSerieDraft($client, $token);
        $seasonId = $this->createSeason($client, $serieId, $token);

        $response = $this->postJson(
            $client,
            sprintf('/api/studio/series/%s/seasons/%s/episodes', $serieId, $seasonId),
            ['number' => 0, 'title' => 'Invalide'],
            $token,
        );

        $this->assertJsonResponse($response, Response::HTTP_BAD_REQUEST);
    }

    // ─── Bug fix : GET serie doit inclure season.episodes ────────

    public function testGetSerieIncludesEpisodesInSeasons(): void
    {
        $client = static::createClient();
        [, , $token] = $this->createCreatorWithStudio();
        $serieId = $this->createSerieDraft($client, $token);
        $seasonId = $this->createSeason($client, $serieId, $token);

        // Crée 2 épisodes dans la saison
        $this->postJson(
            $client,
            sprintf('/api/studio/series/%s/seasons/%s/episodes', $serieId, $seasonId),
            ['number' => 1, 'title' => 'Pilote', 'duration' => 45],
            $token,
        );
        $this->postJson(
            $client,
            sprintf('/api/studio/series/%s/seasons/%s/episodes', $serieId, $seasonId),
            ['number' => 2, 'title' => 'Second épisode', 'duration' => 48],
            $token,
        );

        // GET de la série : les saisons doivent contenir leurs épisodes,
        // sinon la page d'édition studio affiche "0 épisode" alors qu'ils
        // existent en DB.
        $response = $this->getJson(
            $client,
            sprintf('/api/studio/series/%s', $serieId),
            $token,
        );
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $this->assertArrayHasKey('seasons', $body);
        $this->assertCount(1, $body['seasons']);
        $season = $body['seasons'][0];

        $this->assertArrayHasKey('episodes', $season, 'GET serie must expose season.episodes');
        $this->assertCount(2, $season['episodes']);
        $this->assertSame('Pilote', $season['episodes'][0]['title']);
        $this->assertSame(1, $season['episodes'][0]['number']);
        $this->assertSame('Second épisode', $season['episodes'][1]['title']);
        $this->assertSame(2, $season['episodes'][1]['number']);
    }
}
