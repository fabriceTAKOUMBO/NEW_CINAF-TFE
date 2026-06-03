<?php

namespace App\Tests\Studio\Controller;

use App\Entity\Film;
use App\Entity\WithdrawalRequest;
use App\Tests\Studio\Support\StudioTestTrait;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests fonctionnels pour /api/studio/films (Phase B — Studio API).
 *
 * NB : le nom de classe doit correspondre au nom de fichier (PSR-4), sinon
 * PHPUnit ne charge pas la classe (« cannot be found ») et ses tests sont
 * silencieusement ignorés. Renommé Producer→Studio le 2026-06-03.
 */
class StudioFilmControllerTest extends ApiTestCase
{
    use StudioTestTrait;

    public function testCreatorCreatesDraftFilm(): void
    {
        $client = static::createClient();
        [, $studio, $token] = $this->createCreatorWithStudio();

        $response = $this->postJson($client, '/api/studio/films', [
            'title' => 'Mon Premier Film',
            'synopsis' => 'Synopsis du film',
            'year' => 2024,
            'duration' => 95,
        ], $token);

        $body = $this->assertJsonResponse($response, Response::HTTP_CREATED);
        $this->assertSame('DRAFT', $body['status']);
        $this->assertSame($studio->getId()->toRfc4122(), $body['studioId']);
        $this->assertSame('Mon Premier Film', $body['title']);
    }

    public function testCreatorListsOnlyOwnFilms(): void
    {
        $client = static::createClient();
        [, $studioA, $tokenA] = $this->createCreatorWithStudio();
        [, $studioB, $tokenB] = $this->createOtherCreatorWithStudio();

        // Créateur A crée 2 films
        $this->postJson($client, '/api/studio/films', [
            'title' => 'Film A1', 'synopsis' => 's', 'year' => 2024, 'duration' => 90,
        ], $tokenA);
        $this->postJson($client, '/api/studio/films', [
            'title' => 'Film A2', 'synopsis' => 's', 'year' => 2024, 'duration' => 90,
        ], $tokenA);

        // Créateur B crée 1 film
        $this->postJson($client, '/api/studio/films', [
            'title' => 'Film B1', 'synopsis' => 's', 'year' => 2024, 'duration' => 90,
        ], $tokenB);

        // A liste : doit voir 2 films, tous avec studioId = studioA
        $resp = $this->getJson($client, '/api/studio/films', $tokenA);
        $body = $this->assertJsonResponse($resp, Response::HTTP_OK);
        $this->assertSame(2, $body['total']);
        foreach ($body['data'] as $row) {
            $this->assertSame($studioA->getId()->toRfc4122(), $row['studioId']);
        }

        // B liste : doit voir 1 film
        $resp = $this->getJson($client, '/api/studio/films', $tokenB);
        $body = $this->assertJsonResponse($resp, Response::HTTP_OK);
        $this->assertSame(1, $body['total']);
        $this->assertSame($studioB->getId()->toRfc4122(), $body['data'][0]['studioId']);
    }

    public function testCreatorCannotEditOtherStudioFilm403(): void
    {
        $client = static::createClient();
        [, , $tokenA] = $this->createCreatorWithStudio();
        [, , $tokenB] = $this->createOtherCreatorWithStudio();

        // A crée un film
        $resp = $this->postJson($client, '/api/studio/films', [
            'title' => 'Film A', 'synopsis' => 's', 'year' => 2024, 'duration' => 90,
        ], $tokenA);
        $created = json_decode($resp->getContent(), true);

        // B tente de l'éditer
        $resp = $this->patchJson($client, '/api/studio/films/' . $created['id'], [
            'title' => 'Hijack',
        ], $tokenB);

        $this->assertSame(Response::HTTP_FORBIDDEN, $resp->getStatusCode());
    }

    public function testCreatorCannotDeletePublishedFilm409(): void
    {
        $client = static::createClient();
        [, , $token] = $this->createCreatorWithStudio();

        // Créer puis publier
        $resp = $this->postJson($client, '/api/studio/films', [
            'title' => 'Film à publier', 'synopsis' => 's', 'year' => 2024, 'duration' => 90,
        ], $token);
        $film = json_decode($resp->getContent(), true);

        $client->request('POST', '/api/studio/films/' . $film['id'] . '/publish', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $this->assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        // Delete sur film PUBLISHED → 409
        $resp = $this->deleteJson($client, '/api/studio/films/' . $film['id'], $token);
        $this->assertSame(Response::HTTP_CONFLICT, $resp->getStatusCode());
    }

    public function testCreatorPublishesOwnFilmDraftToPublished(): void
    {
        $client = static::createClient();
        [, , $token] = $this->createCreatorWithStudio();

        $resp = $this->postJson($client, '/api/studio/films', [
            'title' => 'Film Pub', 'synopsis' => 's', 'year' => 2024, 'duration' => 90,
        ], $token);
        $film = json_decode($resp->getContent(), true);

        $client->request('POST', '/api/studio/films/' . $film['id'] . '/publish', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $body = $this->assertJsonResponse($client->getResponse(), Response::HTTP_OK);
        $this->assertSame('PUBLISHED', $body['status']);
        $this->assertNotNull($body['publishedAt']);
    }

    public function testCreatorPublishAlreadyPublished400(): void
    {
        $client = static::createClient();
        [, , $token] = $this->createCreatorWithStudio();

        $resp = $this->postJson($client, '/api/studio/films', [
            'title' => 'Film Double', 'synopsis' => 's', 'year' => 2024, 'duration' => 90,
        ], $token);
        $film = json_decode($resp->getContent(), true);

        // 1er publish OK
        $client->request('POST', '/api/studio/films/' . $film['id'] . '/publish', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $this->assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        // 2e publish → 400
        $client->request('POST', '/api/studio/films/' . $film['id'] . '/publish', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $this->assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
    }

    public function testCreatorRequestsWithdrawalCreatesPending(): void
    {
        $client = static::createClient();
        [, $studio, $token] = $this->createCreatorWithStudio();

        $resp = $this->postJson($client, '/api/studio/films', [
            'title' => 'Film Withdraw', 'synopsis' => 's', 'year' => 2024, 'duration' => 90,
        ], $token);
        $film = json_decode($resp->getContent(), true);

        $resp = $this->postJson($client, '/api/studio/films/' . $film['id'] . '/withdraw', [
            'reason' => 'Erreur de droits',
        ], $token);

        $body = $this->assertJsonResponse($resp, Response::HTTP_CREATED);
        $this->assertSame(WithdrawalRequest::STATUS_PENDING, $body['status']);
        $this->assertSame('film', $body['targetType']);
        $this->assertSame($film['id'], $body['targetId']);
        $this->assertSame($studio->getId()->toRfc4122(), $body['studioId']);
    }

    public function testDuplicatePendingWithdrawalReturns409(): void
    {
        $client = static::createClient();
        [, , $token] = $this->createCreatorWithStudio();

        $resp = $this->postJson($client, '/api/studio/films', [
            'title' => 'Film Dup', 'synopsis' => 's', 'year' => 2024, 'duration' => 90,
        ], $token);
        $film = json_decode($resp->getContent(), true);

        // 1ère demande OK
        $resp = $this->postJson($client, '/api/studio/films/' . $film['id'] . '/withdraw', [
            'reason' => 'Première raison',
        ], $token);
        $this->assertSame(Response::HTTP_CREATED, $resp->getStatusCode());

        // 2e → 409
        $resp = $this->postJson($client, '/api/studio/films/' . $film['id'] . '/withdraw', [
            'reason' => 'Deuxième raison',
        ], $token);
        $this->assertSame(Response::HTTP_CONFLICT, $resp->getStatusCode());
    }

    public function testUserWithoutRoleCreator403(): void
    {
        $client = static::createClient();
        [, $token] = $this->createPlainUserWithToken();

        $resp = $this->getJson($client, '/api/studio/films', $token);
        $this->assertSame(Response::HTTP_FORBIDDEN, $resp->getStatusCode());
    }

    public function testAnonymous401(): void
    {
        $client = static::createClient();
        $resp = $this->getJson($client, '/api/studio/films');
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $resp->getStatusCode());
    }

    public function testInvalidUuid400(): void
    {
        $client = static::createClient();
        [, , $token] = $this->createCreatorWithStudio();

        $resp = $this->getJson($client, '/api/studio/films/not-a-uuid', $token);
        $this->assertSame(Response::HTTP_BAD_REQUEST, $resp->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // Phase H — Hardening : Bunny path ownership
    // -----------------------------------------------------------------------

    public function testCreatorCannotSetOtherStudioBunnyPathOnCreate403(): void
    {
        $client = static::createClient();
        [, , $tokenA] = $this->createCreatorWithStudio('studio-a-' . bin2hex(random_bytes(3)));
        [, $studioB] = $this->createOtherCreatorWithStudio();

        // Le creator A tente de créer un film avec un bunnyVideoId qui pointe
        // vers le studio B → 403.
        $resp = $this->postJson($client, '/api/studio/films', [
            'title' => 'Hack',
            'synopsis' => 's',
            'year' => 2024,
            'duration' => 90,
            'bunnyVideoId' => sprintf('studios/%s/videos/hack.mp4', $studioB->getSlug()),
        ], $tokenA);

        $this->assertSame(Response::HTTP_FORBIDDEN, $resp->getStatusCode());
    }

    public function testCreatorCannotSetOtherStudioBunnyPathOnPatch403(): void
    {
        $client = static::createClient();
        [, , $tokenA] = $this->createCreatorWithStudio('studio-a2-' . bin2hex(random_bytes(3)));
        [, $studioB] = $this->createOtherCreatorWithStudio();

        // Crée un film valide
        $resp = $this->postJson($client, '/api/studio/films', [
            'title' => 'Mon Film', 'synopsis' => 's', 'year' => 2024, 'duration' => 90,
        ], $tokenA);
        $film = json_decode($resp->getContent(), true);

        // PATCH avec un path d'un autre studio → 403
        $resp = $this->patchJson($client, '/api/studio/films/' . $film['id'], [
            'bunnyVideoId' => sprintf('studios/%s/videos/foo.mp4', $studioB->getSlug()),
        ], $tokenA);

        $this->assertSame(Response::HTTP_FORBIDDEN, $resp->getStatusCode());
    }

    public function testCreatorCanSetOwnStudioBunnyPath(): void
    {
        $client = static::createClient();
        [, $studio, $token] = $this->createCreatorWithStudio();

        $ownPath = sprintf('studios/%s/videos/legit.mp4', $studio->getSlug());

        // Création avec son propre path → 201
        $resp = $this->postJson($client, '/api/studio/films', [
            'title' => 'Legit',
            'synopsis' => 's',
            'year' => 2024,
            'duration' => 90,
            'bunnyVideoId' => $ownPath,
        ], $token);
        $body = $this->assertJsonResponse($resp, Response::HTTP_CREATED);
        $this->assertSame($ownPath, $body['bunnyVideoId']);

        // PATCH avec son propre path → 200
        $newPath = sprintf('studios/%s/videos/legit-v2.mp4', $studio->getSlug());
        $resp = $this->patchJson($client, '/api/studio/films/' . $body['id'], [
            'bunnyVideoId' => $newPath,
        ], $token);
        $body = $this->assertJsonResponse($resp, Response::HTTP_OK);
        $this->assertSame($newPath, $body['bunnyVideoId']);
    }

    public function testCreatorCannotPatchImportedBunnyPath403(): void
    {
        $client = static::createClient();
        [$user, $studio, $token] = $this->createCreatorWithStudio();

        // Crée un film avec un path "importé" (ne commence pas par studios/)
        // directement en base — simule le scénario Phase F (import Bunny).
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $film = new Film();
        $film->setTitle('Imported');
        $film->setSlug('imported-' . bin2hex(random_bytes(3)));
        $film->setSynopsis('Synopsis');
        $film->setYear(2024);
        $film->setDuration(90);
        $film->setBunnyVideoId('12_CAS/CAS_1/CAS1_E01'); // Path importé
        $film->setStudio($studio);
        $film->setStatus(Film::STATUS_PUBLISHED);
        $film->setPublishedAt(new \DateTimeImmutable());
        $em->persist($film);
        $em->flush();

        // PATCH du bunnyVideoId → 403 même si on essaie de le mettre à un path
        // valide pour le studio (interdiction de modifier un path importé).
        $resp = $this->patchJson($client, '/api/studio/films/' . $film->getId()->toRfc4122(), [
            'bunnyVideoId' => sprintf('studios/%s/videos/replace.mp4', $studio->getSlug()),
        ], $token);

        $this->assertSame(Response::HTTP_FORBIDDEN, $resp->getStatusCode());
    }
}
