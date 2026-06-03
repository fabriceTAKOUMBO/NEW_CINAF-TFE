<?php

namespace App\Tests\Support;

use App\Entity\Episode;
use App\Entity\Film;
use App\Entity\Season;
use App\Entity\Serie;
use App\Entity\Studio;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Base class for all CINAF v2 functional API tests.
 *
 * Provides helpers for authenticated HTTP calls, JSON assertions,
 * and database seeding with the current entity API (title / year / ...).
 *
 * Règle anti « kernel booted once » : dans chaque test, appeler
 * createClient() / createAuthenticatedClient() AVANT tout accès à
 * static::getContainer() (les deux bootent le kernel, et WebTestCase
 * interdit de booter le kernel deux fois).
 */
abstract class ApiTestCase extends WebTestCase
{
    // -----------------------------------------------------------------------
    // HTTP helpers
    // -----------------------------------------------------------------------

    /**
     * POST JSON to an endpoint.
     */
    protected function postJson(
        KernelBrowser $client,
        string $url,
        array $payload,
        ?string $token = null
    ): Response {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($token !== null) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }

        $client->request('POST', $url, [], [], $headers, json_encode($payload));

        return $client->getResponse();
    }

    /**
     * GET JSON from an endpoint.
     */
    protected function getJson(
        KernelBrowser $client,
        string $url,
        ?string $token = null
    ): Response {
        $headers = ['HTTP_ACCEPT' => 'application/json'];
        if ($token !== null) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }

        $client->request('GET', $url, [], [], $headers);

        return $client->getResponse();
    }

    /**
     * PATCH JSON to an endpoint (uses application/merge-patch+json as per RFC 7396).
     */
    protected function patchJson(
        KernelBrowser $client,
        string $url,
        array $payload,
        string $token
    ): Response {
        $headers = [
            'CONTENT_TYPE'     => 'application/merge-patch+json',
            'HTTP_ACCEPT'      => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ];

        $client->request('PATCH', $url, [], [], $headers, json_encode($payload));

        return $client->getResponse();
    }

    /**
     * DELETE an endpoint (requires auth).
     */
    protected function deleteJson(
        KernelBrowser $client,
        string $url,
        string $token
    ): Response {
        $headers = [
            'HTTP_ACCEPT'        => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ];

        $client->request('DELETE', $url, [], [], $headers);

        return $client->getResponse();
    }

    // -----------------------------------------------------------------------
    // Auth helpers
    // -----------------------------------------------------------------------

    /**
     * Create a user in the test DB and return a JWT token for that user.
     *
     * @param string $role  One of 'ROLE_USER' | 'ROLE_ADMIN'
     * @param string $email Override the generated email (optional)
     *
     * @return array{0: KernelBrowser, 1: string, 2: User}
     */
    protected function createAuthenticatedClient(
        string $role = 'ROLE_USER',
        string $email = ''
    ): array {
        // createClient() must run FIRST (it boots the kernel). All container
        // access below reuses that same kernel — never boot it beforehand.
        $client = static::createClient();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        /** @var JWTTokenManagerInterface $jwtManager */
        $jwtManager = static::getContainer()->get(JWTTokenManagerInterface::class);

        if ($email === '') {
            $email = 'test_' . Uuid::v4()->toRfc4122() . '@cinaf-test.com';
        }

        $user = new User();
        $user->setEmail($email);
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setPassword($hasher->hashPassword($user, 'Password123!'));
        $user->setRoles([$role]);
        $user->setIsVerified(true);

        $em->persist($user);
        $em->flush();

        $token = $jwtManager->create($user);

        return [$client, $token, $user];
    }

    // -----------------------------------------------------------------------
    // Database seeding helpers (current entity API)
    //
    // À appeler APRÈS createClient()/createAuthenticatedClient() : ces méthodes
    // utilisent l'EntityManager du kernel déjà démarré.
    // -----------------------------------------------------------------------

    /**
     * Create and persist a studio (with its owner user) in the test DB.
     *
     * Required because film.studio_id / serie.studio_id are NOT NULL in the
     * current schema (Phase F). Each call creates a fresh owner + studio with
     * unique name / slug / bunnyFolder.
     */
    protected function seedStudio(EntityManagerInterface $em): Studio
    {
        $suffix = substr(Uuid::v4()->toRfc4122(), 0, 8);

        $owner = new User();
        $owner->setEmail('studio_owner_' . $suffix . '@cinaf-test.com');
        $owner->setFirstName('Studio');
        $owner->setLastName('Owner');
        $owner->setPassword('not-used-in-tests');
        $owner->setRoles(['ROLE_CREATEUR']);
        $owner->setIsVerified(true);
        $em->persist($owner);

        $studio = new Studio();
        $studio->setName('Studio Test ' . $suffix);
        $studio->setSlug('studio-test-' . $suffix);
        $studio->setBunnyFolder('studios/studio-test-' . $suffix . '/');
        $studio->setOwner($owner);
        $studio->setIsValidated(true);
        $em->persist($studio);

        $em->flush();

        return $studio;
    }

    /**
     * Create and persist a valid PUBLISHED film (with its owning studio) in the
     * test DB.
     *
     * @param array<string,mixed> $overrides Optional keys: title, slug, synopsis,
     *                                        year, duration, views, bunnyVideoId,
     *                                        status, studio.
     */
    protected function seedPublishedFilm(EntityManagerInterface $em, array $overrides = []): Film
    {
        $suffix = substr(Uuid::v4()->toRfc4122(), 0, 8);

        $film = new Film();
        $film->setTitle($overrides['title'] ?? ('Film Test ' . $suffix));
        $film->setSlug($overrides['slug'] ?? ('film-test-' . $suffix));
        $film->setSynopsis($overrides['synopsis'] ?? 'Synopsis de test.');
        $film->setYear($overrides['year'] ?? 2020);
        $film->setDuration($overrides['duration'] ?? 120);
        $film->setViews($overrides['views'] ?? 0);
        $film->setBunnyVideoId($overrides['bunnyVideoId'] ?? ('bunny-' . $suffix));
        $film->setStatus($overrides['status'] ?? Film::STATUS_PUBLISHED);
        $film->setStudio($overrides['studio'] ?? $this->seedStudio($em));

        $em->persist($film);
        $em->flush();

        return $film;
    }

    /**
     * Create and persist a valid PUBLISHED serie in the test DB.
     *
     * @param array<string,mixed> $overrides Optional keys: title, slug, synopsis,
     *                                        year, status, studio.
     */
    protected function seedPublishedSerie(EntityManagerInterface $em, array $overrides = []): Serie
    {
        $suffix = substr(Uuid::v4()->toRfc4122(), 0, 8);

        $serie = new Serie();
        $serie->setTitle($overrides['title'] ?? ('Serie Test ' . $suffix));
        $serie->setSlug($overrides['slug'] ?? ('serie-test-' . $suffix));
        $serie->setSynopsis($overrides['synopsis'] ?? 'Synopsis de test.');
        $serie->setYear($overrides['year'] ?? 2019);
        $serie->setStatus($overrides['status'] ?? Serie::STATUS_PUBLISHED);
        $serie->setStudio($overrides['studio'] ?? $this->seedStudio($em));

        $em->persist($serie);
        $em->flush();

        return $serie;
    }

    /**
     * Create a PUBLISHED serie with season 1 + one episode (bunnyVideoId set),
     * and return the episode — used by the /api/episodes/* tests.
     */
    protected function seedPublishedEpisode(EntityManagerInterface $em): Episode
    {
        $serie = $this->seedPublishedSerie($em);
        $suffix = substr(Uuid::v4()->toRfc4122(), 0, 8);

        $season = new Season();
        $season->setSerie($serie);
        $season->setNumber(1);
        $season->setTitle('Saison 1');

        $episode = new Episode();
        $episode->setSeason($season);
        $episode->setNumber(1);
        $episode->setTitle('Episode 1');
        $episode->setDuration(45);
        $episode->setBunnyVideoId('bunny-ep-' . $suffix);

        // Tenir la collection inverse à jour : la season reste en identity map
        // dans le même EntityManager, donc le contrôleur récupère CETTE instance.
        // Sans cet add(), sa collection episodes (créée vide par `new`) resterait
        // vide même après persistance de l'épisode.
        $season->getEpisodes()->add($episode);

        $serie->setNbSeasons(1);
        $em->persist($season);
        $em->persist($episode);
        $em->flush();

        return $episode;
    }

    // -----------------------------------------------------------------------
    // JSON assertion helpers
    // -----------------------------------------------------------------------

    /**
     * Assert that a response is JSON and decode it.
     */
    protected function assertJsonResponse(Response $response, int $expectedStatus): array
    {
        $this->assertSame($expectedStatus, $response->getStatusCode(), sprintf(
            'HTTP status mismatch. Response body: %s',
            $response->getContent()
        ));
        $this->assertJson($response->getContent());

        return json_decode($response->getContent(), true);
    }

    /**
     * Assert that a paginated catalogue response has the expected structure.
     */
    protected function assertPaginatedStructure(array $body): void
    {
        $this->assertArrayHasKey('data', $body, 'Missing "data" key in paginated response');
        $this->assertArrayHasKey('total', $body, 'Missing "total" key in paginated response');
        $this->assertArrayHasKey('page', $body, 'Missing "page" key in paginated response');
        $this->assertArrayHasKey('limit', $body, 'Missing "limit" key in paginated response');
        $this->assertIsArray($body['data'], '"data" must be an array');
    }

    /**
     * Generate a random UUID v4 string — useful for 404 tests.
     */
    protected function randomUuid(): string
    {
        return Uuid::v4()->toRfc4122();
    }
}
