<?php

namespace App\Tests\Admin\Controller;

use App\Entity\Serie;
use App\Entity\Studio;
use App\Tests\Studio\Support\StudioTestTrait;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Tests fonctionnels pour /api/admin/series (Phase C).
 */
class AdminSerieControllerTest extends ApiTestCase
{
    use StudioTestTrait;

    private function createSerie(Studio $studio, string $title, string $status = Serie::STATUS_DRAFT): Serie
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $slugger = new AsciiSlugger();

        $serie = new Serie();
        $serie->setTitle($title);
        $serie->setSlug(strtolower((string) $slugger->slug($title . '-' . bin2hex(random_bytes(3)))));
        $serie->setSynopsis('Synopsis de ' . $title);
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

    public function test_admin_lists_all_series_across_studios(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [, $studioA] = $this->createCreatorWithStudio();
        [, $studioB] = $this->createOtherCreatorWithStudio();
        $this->createSerie($studioA, 'SerieCross-A1');
        $this->createSerie($studioB, 'SerieCross-B1');

        $response = $this->getJson($client, '/api/admin/series?limit=50', $token);
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $titles = array_column($body['data'], 'title');
        $this->assertContains('SerieCross-A1', $titles);
        $this->assertContains('SerieCross-B1', $titles);
    }

    public function test_admin_filters_serie_by_status(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [, $studio] = $this->createCreatorWithStudio();
        $this->createSerie($studio, 'SerieDraft', Serie::STATUS_DRAFT);
        $this->createSerie($studio, 'SeriePub', Serie::STATUS_PUBLISHED);

        $response = $this->getJson($client, '/api/admin/series?status=PUBLISHED&limit=50', $token);
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        foreach ($body['data'] as $row) {
            $this->assertSame('PUBLISHED', $row['status']);
        }
    }

    public function test_admin_get_serie_detail(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [, $studio] = $this->createCreatorWithStudio();
        $serie = $this->createSerie($studio, 'SerieDetail');

        $response = $this->getJson($client, '/api/admin/series/' . $serie->getId()->toRfc4122(), $token);
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $this->assertSame('SerieDetail', $body['title']);
        $this->assertArrayHasKey('studio', $body);
        $this->assertSame($studio->getId()->toRfc4122(), $body['studio']['id']);
    }

    public function test_admin_patches_serie(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [, $studio] = $this->createCreatorWithStudio();
        $serie = $this->createSerie($studio, 'SeriePreEdit');

        $response = $this->patchJson(
            $client,
            '/api/admin/series/' . $serie->getId()->toRfc4122(),
            ['title' => 'SeriePostEdit', 'status' => 'PUBLISHED'],
            $token,
        );
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $this->assertSame('SeriePostEdit', $body['title']);
        $this->assertSame('PUBLISHED', $body['status']);
    }

    public function test_admin_deletes_serie(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [, $studio] = $this->createCreatorWithStudio();
        $serie = $this->createSerie($studio, 'SerieToDelete');
        $serieId = $serie->getId();

        $client->request(
            'DELETE',
            '/api/admin/series/' . $serieId->toRfc4122(),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );
        $this->assertSame(204, $client->getResponse()->getStatusCode());

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $em->getRepository(Serie::class)->find($serieId);
        $this->assertNull($reloaded);
    }

    public function test_admin_serie_non_admin_403(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_USER');
        $response = $this->getJson($client, '/api/admin/series', $token);
        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }
}
