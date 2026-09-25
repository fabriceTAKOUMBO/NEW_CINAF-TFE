<?php

namespace App\Tests\Studio\Controller;

use App\Entity\Studio;
use App\Entity\User;
use App\Tests\Studio\Support\StudioTestTrait;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Tests fonctionnels du parcours self-service "Je suis producteur" :
 * POST /api/studio/onboarding.
 *
 * Vérifie la création du studio (non validé, dossier Bunny `studios/{slug}/`,
 * ROLE_CREATEUR ajouté en base) et les refus : 409 si l'utilisateur a déjà un
 * studio ou si le nom est pris, 403 pour un administrateur, 401 sans JWT,
 * 400 si le nom ou la description est vide. La suite du parcours (publication
 * en attente puis approbation) est couverte par tests/E2E/StudioOnboardingE2ETest.
 *
 * Lancement : php bin/phpunit tests/Studio/Controller/StudioOnboardingControllerTest.php
 */
class StudioOnboardingControllerTest extends ApiTestCase
{
    use StudioTestTrait;

    /**
     * Un ROLE_USER crée son studio : 201, studio actif mais non validé, dossier Bunny
     * `studios/{slug}/`, et ROLE_CREATEUR effectivement ajouté à l'utilisateur en base.
     */
    public function testPlainUserCreatesOwnStudio(): void
    {
        [$client, $token, $user] = $this->createAuthenticatedClient('ROLE_USER');

        $response = $this->postJson($client, '/api/studio/onboarding', [
            'name' => 'Studio Onboarding ' . Uuid::v4()->toRfc4122(),
            'description' => 'Un studio créé en self-service depuis le footer.',
        ], $token);

        $body = $this->assertJsonResponse($response, Response::HTTP_CREATED);

        $this->assertArrayHasKey('id', $body);
        $this->assertArrayHasKey('slug', $body);
        $this->assertArrayHasKey('bunnyFolder', $body);
        $this->assertFalse($body['isValidated'], 'Le studio doit démarrer non validé.');
        $this->assertTrue($body['isActive']);
        $this->assertSame(sprintf('studios/%s/', $body['slug']), $body['bunnyFolder']);

        // Vérifie en DB que ROLE_CREATEUR a bien été ajouté.
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $refreshed = $em->getRepository(User::class)->find($user->getId());
        $this->assertNotNull($refreshed);
        $this->assertContains('ROLE_CREATEUR', $refreshed->getRoles());
        $this->assertNotNull($refreshed->getStudio());
    }

    /**
     * Un utilisateur qui possède déjà un studio ne peut pas en créer un second : 409.
     */
    public function testUserWhoAlreadyHasStudioGets409(): void
    {
        $client = static::createClient();
        [, , $token] = $this->createCreatorWithStudio();

        $response = $this->postJson($client, '/api/studio/onboarding', [
            'name' => 'Tentative ' . Uuid::v4()->toRfc4122(),
            'description' => 'Doublon studio.',
        ], $token);

        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    /**
     * Deux utilisateurs visent le même nom de studio : le premier obtient 201, le second 409.
     */
    public function testDuplicateNameGets409(): void
    {
        // Boot du kernel partagé : on construit les 2 users via l'EM
        // sans rappeler `createAuthenticatedClient()` (qui appellerait
        // `createClient()` deux fois et casserait avec "kernel double-boot").
        [$client, $tokenA] = $this->createAuthenticatedClient('ROLE_USER');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        /** @var JWTTokenManagerInterface $jwt */
        $jwt = static::getContainer()->get(JWTTokenManagerInterface::class);

        $userB = new User();
        $userB->setEmail('userb_' . Uuid::v4()->toRfc4122() . '@cinaf-test.com');
        $userB->setFirstName('Other');
        $userB->setLastName('User');
        $userB->setRoles(['ROLE_USER']);
        $userB->setIsVerified(true);
        $userB->setPassword($hasher->hashPassword($userB, 'Password123!'));
        $em->persist($userB);
        $em->flush();
        $tokenB = $jwt->create($userB);

        $name = 'Studio Unique ' . Uuid::v4()->toRfc4122();

        $resp = $this->postJson($client, '/api/studio/onboarding', [
            'name' => $name,
            'description' => 'Premier studio.',
        ], $tokenA);
        $this->assertSame(Response::HTTP_CREATED, $resp->getStatusCode());

        // Deuxième user tente de prendre le même nom.
        $resp2 = $this->postJson($client, '/api/studio/onboarding', [
            'name' => $name,
            'description' => 'Deuxième studio même nom.',
        ], $tokenB);
        $this->assertSame(Response::HTTP_CONFLICT, $resp2->getStatusCode());
    }

    /**
     * Un administrateur ne peut pas créer de studio (séparation stricte Phase H) : 403.
     */
    public function testAdminCannotCreateStudio403(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');

        $response = $this->postJson($client, '/api/studio/onboarding', [
            'name' => 'Studio Admin ' . Uuid::v4()->toRfc4122(),
            'description' => 'Tentative admin.',
        ], $token);

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    /**
     * Requête sans JWT : 401.
     */
    public function testAnonymousGets401(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/studio/onboarding',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode([
                'name' => 'Anonyme ' . Uuid::v4()->toRfc4122(),
                'description' => 'Sans token.',
            ]),
        );

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
    }

    /**
     * Nom réduit à des espaces : 400.
     */
    public function testEmptyNameGets400(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_USER');

        $response = $this->postJson($client, '/api/studio/onboarding', [
            'name' => '   ',
            'description' => 'Desc valide.',
        ], $token);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    /**
     * Description vide : 400.
     */
    public function testEmptyDescriptionGets400(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_USER');

        $response = $this->postJson($client, '/api/studio/onboarding', [
            'name' => 'Studio Sans Desc ' . Uuid::v4()->toRfc4122(),
            'description' => '',
        ], $token);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }
}
