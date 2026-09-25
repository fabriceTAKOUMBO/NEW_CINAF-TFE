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
 *
 * Les séries, saisons et épisodes sont créés via l'API elle-même (helpers
 * createSerieDraft() / createSeason()), avec un créateur de StudioTestTrait.
 *
 * Lancement : php bin/phpunit tests/Studio/Controller/StudioSerieControllerTest.php
 */
class StudioSerieControllerTest extends ApiTestCase
{
    use StudioTestTrait;

    /**
     * Crée une série DRAFT via POST /api/studio/series et renvoie son identifiant
     * (le test échoue si la réponse n'est pas 201).
     */
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

    /**
     * Ajoute la saison `$number` à la série via l'API et renvoie son identifiant
     * (le test échoue si la réponse n'est pas 201).
     */
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

    /**
     * Deux épisodes de même numéro dans une saison : le second reçoit 409
     * (pré-contrôle du contrôleur), et non une erreur 500.
     */
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

    /**
     * Un numéro d'épisode inférieur à 1 est refusé : 400.
     */
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

    /**
     * Le détail d'une série expose `seasons[].episodes`, triés par numéro
     * (non-régression du bug corrigé le 2026-05-13).
     */
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
