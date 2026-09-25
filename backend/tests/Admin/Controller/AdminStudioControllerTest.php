<?php

namespace App\Tests\Admin\Controller;

use App\Tests\Studio\Support\StudioTestTrait;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests fonctionnels pour /api/admin/studios (Phase D / Agent 4).
 * Endpoint minimaliste utilisé par l'UI admin pour alimenter le
 * filtre "studio" dans la liste des films & séries.
 *
 * Contrôleur testé : App\Admin\Controller\AdminStudioController
 * (`GET /api/admin/studios`, lecture seule : studios actifs triés par slug,
 * sans pagination). Vérifié : un admin reçoit `{data, total}` contenant les
 * deux studios créés par le test, au format Studio::toArray() ; un
 * utilisateur ROLE_USER reçoit 403.
 *
 * Lancement : `php bin/phpunit tests/Admin/Controller/AdminStudioControllerTest.php`.
 */
class AdminStudioControllerTest extends ApiTestCase
{
    use StudioTestTrait;

    /**
     * Un admin liste les studios actifs : 200, `total` ≥ 2, les deux studios
     * créés ici (actifs, via StudioTestTrait) sont présents, et un élément de la
     * liste expose bien id, name, slug et isActive.
     */
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
        // (le premier élément, trié par slug, n'est pas forcément un studio créé
        // ici : seule sa forme est contrôlée).
        $first = $body['data'][0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('slug', $first);
        $this->assertArrayHasKey('isActive', $first);
    }

    /** Un utilisateur ROLE_USER ne peut pas lister les studios côté admin : 403. */
    public function test_non_admin_cannot_list_studios(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_USER');

        $response = $this->getJson($client, '/api/admin/studios', $token);

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }
}
