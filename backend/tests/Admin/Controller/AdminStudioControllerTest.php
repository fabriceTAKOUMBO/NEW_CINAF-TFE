<?php

namespace App\Tests\Admin\Controller;

use App\Tests\Studio\Support\StudioTestTrait;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests fonctionnels pour /api/admin/studios (Phase D / Agent 4).
 * Endpoint minimaliste utilisé par l'UI admin pour alimenter le
 * filtre "studio" dans la liste des films & séries.
 */
class AdminStudioControllerTest extends ApiTestCase
{
    use StudioTestTrait;

    public function test_admin_lists_studios(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [, $studioA] = $this->createCreatorWithStudio();
        [, $studioB] = $this->createOtherCreatorWithStudio();

        $response = $this->getJson($client, '/api/admin/studios', $token);
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('total', $body);
        $this->assertGreaterThanOrEqual(2, $body['total']);

        $ids = array_column($body['data'], 'id');
        $this->assertContains($studioA->getId()->toRfc4122(), $ids);
        $this->assertContains($studioB->getId()->toRfc4122(), $ids);

        // Forme d'un studio renvoyée par toArray() : id, name, slug, isActive…
        $first = $body['data'][0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('slug', $first);
        $this->assertArrayHasKey('isActive', $first);
    }

    public function test_non_admin_cannot_list_studios(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_USER');

        $response = $this->getJson($client, '/api/admin/studios', $token);

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }
}
