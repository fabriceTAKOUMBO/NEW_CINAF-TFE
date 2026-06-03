<?php

namespace App\Tests\Admin\Controller;

use App\Entity\Film;
use App\Entity\Studio;
use App\Tests\Studio\Support\StudioTestTrait;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;

/**
 * Tests fonctionnels pour /api/admin/films (Phase C).
 */
class AdminFilmControllerTest extends ApiTestCase
{
    use StudioTestTrait;

    private function createFilm(Studio $studio, string $title, string $status = Film::STATUS_DRAFT): Film
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $slugger = new AsciiSlugger();

        $film = new Film();
        $film->setTitle($title);
        $film->setSlug(strtolower((string) $slugger->slug($title . '-' . bin2hex(random_bytes(3)))));
        $film->setSynopsis('Synopsis de ' . $title);
        $film->setYear(2024);
        $film->setDuration(95);
        $film->setStudio($studio);
        $film->setStatus($status);
        if ($status === Film::STATUS_PUBLISHED) {
            $film->setPublishedAt(new \DateTimeImmutable());
        }
        $em->persist($film);
        $em->flush();

        return $film;
    }

    public function test_admin_lists_all_films_across_studios(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [, $studioA] = $this->createCreatorWithStudio();
        [, $studioB] = $this->createOtherCreatorWithStudio();
        $this->createFilm($studioA, 'Cross-A1');
        $this->createFilm($studioA, 'Cross-A2');
        $this->createFilm($studioB, 'Cross-B1');

        $response = $this->getJson($client, '/api/admin/films?page=1&limit=50', $token);
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $titles = array_column($body['data'], 'title');
        $this->assertContains('Cross-A1', $titles);
        $this->assertContains('Cross-A2', $titles);
        $this->assertContains('Cross-B1', $titles);
        $this->assertGreaterThanOrEqual(3, $body['total']);
    }

    public function test_admin_filters_by_status(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [, $studio] = $this->createCreatorWithStudio();
        $this->createFilm($studio, 'Status-Draft', Film::STATUS_DRAFT);
        $this->createFilm($studio, 'Status-Published', Film::STATUS_PUBLISHED);

        $response = $this->getJson($client, '/api/admin/films?status=PUBLISHED&limit=50', $token);
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        foreach ($body['data'] as $row) {
            $this->assertSame('PUBLISHED', $row['status']);
        }
    }

    public function test_admin_filters_by_studio(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [, $studioA] = $this->createCreatorWithStudio();
        [, $studioB] = $this->createOtherCreatorWithStudio();
        $this->createFilm($studioA, 'StudioA-Only');
        $this->createFilm($studioB, 'StudioB-Only');

        $response = $this->getJson(
            $client,
            '/api/admin/films?studioId=' . $studioA->getId()->toRfc4122() . '&limit=50',
            $token,
        );
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        foreach ($body['data'] as $row) {
            $this->assertSame($studioA->getId()->toRfc4122(), $row['studioId']);
        }
        $titles = array_column($body['data'], 'title');
        $this->assertContains('StudioA-Only', $titles);
        $this->assertNotContains('StudioB-Only', $titles);
    }

    public function test_admin_searches_by_title(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [, $studio] = $this->createCreatorWithStudio();
        $unique = 'UniqueSearchToken' . bin2hex(random_bytes(2));
        $this->createFilm($studio, $unique);
        $this->createFilm($studio, 'Other Title');

        $response = $this->getJson($client, '/api/admin/films?search=' . urlencode($unique), $token);
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $this->assertSame(1, $body['total']);
        $this->assertSame($unique, $body['data'][0]['title']);
    }

    public function test_admin_get_film_detail(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [, $studio] = $this->createCreatorWithStudio();
        $film = $this->createFilm($studio, 'Detail-Film');

        $response = $this->getJson($client, '/api/admin/films/' . $film->getId()->toRfc4122(), $token);
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $this->assertSame('Detail-Film', $body['title']);
        $this->assertArrayHasKey('studio', $body);
        $this->assertSame($studio->getId()->toRfc4122(), $body['studio']['id']);
    }

    public function test_admin_patches_film(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [, $studioA] = $this->createCreatorWithStudio();
        [, $studioB] = $this->createOtherCreatorWithStudio();
        $film = $this->createFilm($studioA, 'Pre-Edit');

        $response = $this->patchJson(
            $client,
            '/api/admin/films/' . $film->getId()->toRfc4122(),
            [
                'title' => 'Post-Edit',
                'status' => 'PUBLISHED',
                'studioId' => $studioB->getId()->toRfc4122(),
            ],
            $token,
        );
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $this->assertSame('Post-Edit', $body['title']);
        $this->assertSame('PUBLISHED', $body['status']);
        $this->assertSame($studioB->getId()->toRfc4122(), $body['studioId']);
        $this->assertNotNull($body['publishedAt']);
    }

    public function test_admin_deletes_film(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [, $studio] = $this->createCreatorWithStudio();
        $film = $this->createFilm($studio, 'To-Delete');
        $filmId = $film->getId();

        $client->request(
            'DELETE',
            '/api/admin/films/' . $filmId->toRfc4122(),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );
        $this->assertSame(204, $client->getResponse()->getStatusCode());

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $em->getRepository(Film::class)->find($filmId);
        $this->assertNull($reloaded);
    }

    public function test_non_admin_403(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_USER');
        $response = $this->getJson($client, '/api/admin/films', $token);
        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_invalid_uuid_400(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        $response = $this->getJson($client, '/api/admin/films/not-a-uuid', $token);
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function test_film_not_found_404(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        $response = $this->getJson($client, '/api/admin/films/' . Uuid::v4()->toRfc4122(), $token);
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }
}
