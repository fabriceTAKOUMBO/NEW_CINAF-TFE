<?php

namespace App\Tests\Controller;

use App\Entity\Film;
use App\Entity\Studio;
use App\Repository\StudioSubscriptionRepository;
use App\Tests\Studio\Support\StudioTestTrait;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests fonctionnels du « follow studio » (abonnement gratuit type YouTube)
 * exposé par `StudioPublicController` sur `/api/studios/{slug}/subscribe`
 * et `/api/studios/{slug}/subscription`.
 *
 * Règles couvertes :
 *  - POST sur un studio public crée une ligne et retourne 201.
 *  - POST en double est idempotent (200, pas de doublon en DB).
 *  - DELETE supprime la ligne et retourne 204.
 *  - DELETE sans abonnement préexistant est idempotent (204 silencieux).
 *  - GET status retourne false avant et true après subscribe.
 *  - Endpoint requiert authentification (401 sinon).
 *  - 404 si le studio n'est pas public (cohérence avec les autres endpoints).
 *
 * Note d'implémentation : on appelle `static::createClient()` AVANT toute
 * autre récupération de service via `static::getContainer()`, sous peine de
 * LogicException « Booting the kernel before createClient ... » (le kernel
 * ne peut être démarré qu'une fois par test).
 */
final class StudioSubscriptionTest extends ApiTestCase
{
    use StudioTestTrait;

    public function testSubscribeReturns201ForAuthenticatedUser(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // On crée un studio public (avec un film PUBLISHED) puis un user
        // lambda authentifié qui va s'abonner.
        [, $studio] = $this->createCreatorWithStudio('sub-' . bin2hex(random_bytes(3)));
        $this->seedFilm($em, $studio, Film::STATUS_PUBLISHED);

        [$user, $token] = $this->createPlainUserWithToken();

        $resp = $this->postJson($client, '/api/studios/' . $studio->getSlug() . '/subscribe', [], $token);
        $body = $this->assertJsonResponse($resp, Response::HTTP_CREATED);

        $this->assertTrue($body['isSubscribed']);
        $this->assertSame(1, $body['subscribersCount']);

        // Vérification DB directe : 1 ligne pour le couple (user, studio).
        /** @var StudioSubscriptionRepository $repo */
        $repo = static::getContainer()->get(StudioSubscriptionRepository::class);
        $this->assertTrue($repo->isSubscribed($user, $studio));
    }

    public function testDoubleSubscribeIsIdempotent(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [, $studio] = $this->createCreatorWithStudio('idem-' . bin2hex(random_bytes(3)));
        $this->seedFilm($em, $studio, Film::STATUS_PUBLISHED);

        [, $token] = $this->createPlainUserWithToken();

        // 1er POST → 201.
        $resp1 = $this->postJson($client, '/api/studios/' . $studio->getSlug() . '/subscribe', [], $token);
        $this->assertSame(Response::HTTP_CREATED, $resp1->getStatusCode());

        // 2e POST → 200 (pas d'erreur, idempotent).
        $resp2 = $this->postJson($client, '/api/studios/' . $studio->getSlug() . '/subscribe', [], $token);
        $body = $this->assertJsonResponse($resp2, Response::HTTP_OK);
        $this->assertTrue($body['isSubscribed']);
        $this->assertSame(1, $body['subscribersCount']); // toujours 1 seule ligne

        // Comptage direct via repo pour confirmer l'unicité.
        /** @var StudioSubscriptionRepository $repo */
        $repo = static::getContainer()->get(StudioSubscriptionRepository::class);
        $this->assertSame(1, $repo->countByStudio($studio));
    }

    public function testUnsubscribeReturns204AndRemovesFromDb(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [, $studio] = $this->createCreatorWithStudio('unsub-' . bin2hex(random_bytes(3)));
        $this->seedFilm($em, $studio, Film::STATUS_PUBLISHED);

        [$user, $token] = $this->createPlainUserWithToken();

        // Setup : on abonne d'abord l'utilisateur.
        $this->postJson($client, '/api/studios/' . $studio->getSlug() . '/subscribe', [], $token);

        // DELETE → 204, ligne supprimée.
        $resp = $this->deleteJson($client, '/api/studios/' . $studio->getSlug() . '/subscribe', $token);
        $this->assertSame(Response::HTTP_NO_CONTENT, $resp->getStatusCode());

        /** @var StudioSubscriptionRepository $repo */
        $repo = static::getContainer()->get(StudioSubscriptionRepository::class);
        $this->assertFalse($repo->isSubscribed($user, $studio));
        $this->assertSame(0, $repo->countByStudio($studio));
    }

    public function testUnsubscribeIdempotentReturns204IfNotSubscribed(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [, $studio] = $this->createCreatorWithStudio('unsub-i-' . bin2hex(random_bytes(3)));
        $this->seedFilm($em, $studio, Film::STATUS_PUBLISHED);

        // L'utilisateur n'a jamais été abonné, mais DELETE doit rester
        // silencieux (sémantique REST classique sur ressource déjà absente).
        [, $token] = $this->createPlainUserWithToken();

        $resp = $this->deleteJson($client, '/api/studios/' . $studio->getSlug() . '/subscribe', $token);
        $this->assertSame(Response::HTTP_NO_CONTENT, $resp->getStatusCode());
    }

    public function testGetSubscriptionReturnsFalseForNonSubscriber(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [, $studio] = $this->createCreatorWithStudio('status-f-' . bin2hex(random_bytes(3)));
        $this->seedFilm($em, $studio, Film::STATUS_PUBLISHED);

        [, $token] = $this->createPlainUserWithToken();

        $resp = $this->getJson($client, '/api/studios/' . $studio->getSlug() . '/subscription', $token);
        $body = $this->assertJsonResponse($resp, Response::HTTP_OK);

        $this->assertFalse($body['isSubscribed']);
    }

    public function testGetSubscriptionReturnsTrueAfterSubscribe(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [, $studio] = $this->createCreatorWithStudio('status-t-' . bin2hex(random_bytes(3)));
        $this->seedFilm($em, $studio, Film::STATUS_PUBLISHED);

        [, $token] = $this->createPlainUserWithToken();

        $this->postJson($client, '/api/studios/' . $studio->getSlug() . '/subscribe', [], $token);

        $resp = $this->getJson($client, '/api/studios/' . $studio->getSlug() . '/subscription', $token);
        $body = $this->assertJsonResponse($resp, Response::HTTP_OK);

        $this->assertTrue($body['isSubscribed']);
    }

    public function testSubscribeReturns401ForAnonymous(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [, $studio] = $this->createCreatorWithStudio('anon-' . bin2hex(random_bytes(3)));
        $this->seedFilm($em, $studio, Film::STATUS_PUBLISHED);

        // Pas de token → 401 (géré par Symfony Security via #[IsGranted]).
        $resp = $this->postJson($client, '/api/studios/' . $studio->getSlug() . '/subscribe', []);
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $resp->getStatusCode());
    }

    public function testSubscribeReturns404ForNonPublicStudio(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // Studio existant mais SANS contenu PUBLISHED → considéré non public.
        [, $studio] = $this->createCreatorWithStudio('np-' . bin2hex(random_bytes(3)));
        $this->seedFilm($em, $studio, Film::STATUS_DRAFT);

        [, $token] = $this->createPlainUserWithToken();

        $resp = $this->postJson($client, '/api/studios/' . $studio->getSlug() . '/subscribe', [], $token);
        $this->assertSame(Response::HTTP_NOT_FOUND, $resp->getStatusCode());
    }

    // ----------------------------------------------------------------
    // Helper interne — copie minimaliste du seed Film de
    // StudioPublicControllerTest (on ne touche pas à ce fichier hors
    // périmètre, donc on réimplémente localement la création d'un Film
    // de test avec le studio fourni).
    // ----------------------------------------------------------------

    private function seedFilm(EntityManagerInterface $em, Studio $studio, string $status): Film
    {
        $uid = bin2hex(random_bytes(4));
        $film = new Film();
        $film->setTitle('Film Test ' . $uid);
        $film->setSlug('film-test-' . $uid);
        $film->setSynopsis('Synopsis de test.');
        $film->setYear(2024);
        $film->setDuration(95);
        $film->setStudio($studio);
        $film->setStatus($status);
        $em->persist($film);
        $em->flush();
        return $film;
    }
}
