<?php

namespace App\Tests\Controller;

use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for public catalogue reference endpoints (Sprint 2):
 *   GET /api/genres
 *   GET /api/countries
 *   GET /api/languages
 *   GET /api/persons
 *
 * All endpoints are PUBLIC — no auth required.
 *
 * Depends on: CatalogueReferenceController, Genre / Country / Language / Person entities.
 *
 * En français : vérifie que les quatre référentiels répondent 200 en JSON,
 * sans authentification. Les contrôles de champs ne s'exécutent que si la
 * liste renvoyée n'est pas vide ; or la base de test est migrée sans
 * fixtures et aucun code ne crée ces référentiels : en pratique, seuls le
 * code HTTP, le type tableau et le Content-Type sont vérifiés.
 *
 * Run: php bin/phpunit tests/Controller/CatalogueReferenceControllerTest.php
 */
class CatalogueReferenceControllerTest extends ApiTestCase
{
    // -----------------------------------------------------------------------
    // Genres
    // -----------------------------------------------------------------------

    /**
     * GET /api/genres → 200, array list with at least {id, name} per item.
     */
    public function testGetGenres(): void
    {
        $client = static::createClient();
        $response = $this->getJson($client, '/api/genres');

        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        // Accept both a simple array response or {data: [...]} envelope
        $items = $body['data'] ?? $body;
        $this->assertIsArray($items, 'Genres response must be an array.');

        // Contrôle conditionnel (idem dans les tests suivants) : ignoré si la
        // liste est vide, ce qui est le cas en base de test (voir l'en-tête).
        if (count($items) > 0) {
            $this->assertArrayHasKey('id', $items[0], 'Genre item must have an "id" field.');
            $this->assertArrayHasKey('name', $items[0], 'Genre item must have a "name" field.');
        }
    }

    /**
     * GET /api/genres — no auth token → still 200 (public endpoint).
     */
    public function testGetGenresIsPublic(): void
    {
        // No token passed
        $client = static::createClient();
        $client->request('GET', '/api/genres', [], [], ['HTTP_ACCEPT' => 'application/json']);

        $this->assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // Countries
    // -----------------------------------------------------------------------

    /**
     * GET /api/countries → 200, array list with at least {id, name, isoCode} per item.
     */
    public function testGetCountries(): void
    {
        $client = static::createClient();
        $response = $this->getJson($client, '/api/countries');

        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $items = $body['data'] ?? $body;
        $this->assertIsArray($items, 'Countries response must be an array.');

        if (count($items) > 0) {
            $this->assertArrayHasKey('id', $items[0]);
            $this->assertArrayHasKey('name', $items[0]);
            $this->assertArrayHasKey('isoCode', $items[0], 'Country item must have an "isoCode" field.');
        }
    }

    /**
     * GET /api/countries is public (no auth).
     */
    public function testGetCountriesIsPublic(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/countries', [], [], ['HTTP_ACCEPT' => 'application/json']);

        $this->assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // Languages
    // -----------------------------------------------------------------------

    /**
     * GET /api/languages → 200, array list with at least {id, name} per item
     * (isoCode is returned but not asserted).
     */
    public function testGetLanguages(): void
    {
        $client = static::createClient();
        $response = $this->getJson($client, '/api/languages');

        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $items = $body['data'] ?? $body;
        $this->assertIsArray($items, 'Languages response must be an array.');

        if (count($items) > 0) {
            $this->assertArrayHasKey('id', $items[0]);
            $this->assertArrayHasKey('name', $items[0]);
        }
    }

    /**
     * GET /api/languages is public.
     */
    public function testGetLanguagesIsPublic(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/languages', [], [], ['HTTP_ACCEPT' => 'application/json']);

        $this->assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // Persons
    // -----------------------------------------------------------------------

    /**
     * GET /api/persons → 200, array list with at least {id, firstName} per item.
     */
    public function testGetPersons(): void
    {
        $client = static::createClient();
        $response = $this->getJson($client, '/api/persons');

        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $items = $body['data'] ?? $body;
        $this->assertIsArray($items, 'Persons response must be an array.');

        if (count($items) > 0) {
            $this->assertArrayHasKey('id', $items[0], 'Person item must have an "id" field.');
            $this->assertArrayHasKey('firstName', $items[0], 'Person item must have a "firstName" field.');
        }
    }

    /**
     * GET /api/persons is public (no auth required).
     */
    public function testGetPersonsIsPublic(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/persons', [], [], ['HTTP_ACCEPT' => 'application/json']);

        $this->assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // Bonus: verify Content-Type header
    // -----------------------------------------------------------------------

    /**
     * Verify all reference endpoints return application/json Content-Type.
     */
    #[DataProvider('referenceEndpointsProvider')]
    public function testReferenceEndpointsReturnJson(string $url): void
    {
        $client = static::createClient();
        $client->request('GET', $url, [], [], ['HTTP_ACCEPT' => 'application/json']);

        $this->assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $this->assertStringContainsString(
            'application/json',
            $client->getResponse()->headers->get('Content-Type') ?? '',
            "Endpoint $url must return application/json."
        );
    }

    /**
     * Fournisseur de données : les quatre URL de référentiel, indexées par nom.
     *
     * @return array<string, array{0: string}>
     */
    public static function referenceEndpointsProvider(): array
    {
        return [
            'genres'    => ['/api/genres'],
            'countries' => ['/api/countries'],
            'languages' => ['/api/languages'],
            'persons'   => ['/api/persons'],
        ];
    }
}
