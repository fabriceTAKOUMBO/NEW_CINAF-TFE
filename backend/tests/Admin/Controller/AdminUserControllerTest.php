<?php

namespace App\Tests\Admin\Controller;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Functional tests for /api/admin/users endpoints (Sprint 7).
 *
 * Run: php bin/phpunit tests/Admin/Controller/AdminUserControllerTest.php
 */
class AdminUserControllerTest extends ApiTestCase
{
    // -----------------------------------------------------------------------
    // 401 — Sans token
    // -----------------------------------------------------------------------

    public function testListRequiresAuth(): void
    {
        $client = static::createClient();
        $response = $this->getJson($client, '/api/admin/users');

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testGetOneRequiresAuth(): void
    {
        $client = static::createClient();
        $response = $this->getJson($client, '/api/admin/users/' . $this->randomUuid());

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testSuspendRequiresAuth(): void
    {
        $client = static::createClient();
        $headers = ['HTTP_ACCEPT' => 'application/json'];
        $client->request('PATCH', '/api/admin/users/' . $this->randomUuid() . '/suspend', [], [], $headers);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
    }

    public function testExportRequiresAuth(): void
    {
        $client = static::createClient();
        $response = $this->getJson($client, '/api/admin/users/export');

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // 403 — Token ROLE_USER (non-admin)
    // -----------------------------------------------------------------------

    public function testListForbiddenForRoleUser(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_USER');
        $response = $this->getJson($client, '/api/admin/users', $token);

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testSuspendForbiddenForRoleUser(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_USER');
        $response = $this->patchJson($client, '/api/admin/users/' . $this->randomUuid() . '/suspend', [], $token);

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // 200 — Token ROLE_ADMIN
    // -----------------------------------------------------------------------

    public function testListAsAdminReturns200WithPaginatedStructure(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        $response = $this->getJson($client, '/api/admin/users?page=1&limit=10', $token);

        $body = $this->assertJsonResponse($response, Response::HTTP_OK);
        $this->assertPaginatedStructure($body);
        $this->assertIsInt($body['total']);
        $this->assertSame(1, $body['page']);
        $this->assertSame(10, $body['limit']);
    }

    public function testListWithSearchFilter(): void
    {
        [$client, $token, $admin] = $this->createAuthenticatedClient('ROLE_ADMIN');

        // Créer un user dédié avec un email unique cherché
        $target = $this->createUser('findme_' . Uuid::v4()->toRfc4122() . '@cinaf-test.com', ['ROLE_USER']);

        $response = $this->getJson($client, '/api/admin/users?search=findme', $token);
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $emails = array_column($body['data'], 'email');
        $this->assertContains($target->getEmail(), $emails);
    }

    public function testListWithRoleFilter(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');

        // Créer un ROLE_MODERATEUR
        $moderator = $this->createUser('mod_' . Uuid::v4()->toRfc4122() . '@cinaf-test.com', ['ROLE_MODERATEUR']);

        $response = $this->getJson($client, '/api/admin/users?role=ROLE_MODERATEUR', $token);
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        // Chaque user du résultat doit contenir ROLE_MODERATEUR
        foreach ($body['data'] as $u) {
            $this->assertContains('ROLE_MODERATEUR', $u['roles']);
        }
    }

    public function testListWithInvalidRoleFilterReturns400(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        $response = $this->getJson($client, '/api/admin/users?role=ROLE_HACKER', $token);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testGetOneNotFound(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        $response = $this->getJson($client, '/api/admin/users/' . $this->randomUuid(), $token);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testGetOneInvalidUuidReturns400(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        $response = $this->getJson($client, '/api/admin/users/not-a-uuid', $token);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testGetOneExisting(): void
    {
        [$client, $token, $admin] = $this->createAuthenticatedClient('ROLE_ADMIN');
        $target = $this->createUser('target_' . Uuid::v4()->toRfc4122() . '@cinaf-test.com', ['ROLE_USER']);

        $response = $this->getJson($client, '/api/admin/users/' . $target->getId()->toRfc4122(), $token);
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $this->assertSame($target->getEmail(), $body['email']);
        $this->assertArrayHasKey('isSuspended', $body);
        $this->assertFalse($body['isSuspended']);
    }

    // -----------------------------------------------------------------------
    // PATCH suspend / activate
    // -----------------------------------------------------------------------

    public function testSuspendUser(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        $target = $this->createUser('suspend_' . Uuid::v4()->toRfc4122() . '@cinaf-test.com', ['ROLE_USER']);

        $response = $this->patchJson(
            $client,
            '/api/admin/users/' . $target->getId()->toRfc4122() . '/suspend',
            [],
            $token,
        );

        $body = $this->assertJsonResponse($response, Response::HTTP_OK);
        $this->assertTrue($body['isSuspended']);

        // Vérif en base (identité claire)
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $em->getRepository(User::class)->find($target->getId());
        $this->assertTrue($reloaded->isSuspended());
    }

    public function testCannotSuspendSelf(): void
    {
        [$client, $token, $admin] = $this->createAuthenticatedClient('ROLE_ADMIN');

        $response = $this->patchJson(
            $client,
            '/api/admin/users/' . $admin->getId()->toRfc4122() . '/suspend',
            [],
            $token,
        );

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testLoginBlockedAfterSuspension(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');

        // Créer un user avec mdp connu
        $email = 'suspended_' . Uuid::v4()->toRfc4122() . '@cinaf-test.com';
        $password = 'Password123!';
        $target = $this->createUser($email, ['ROLE_USER'], $password);

        // Confirmer qu'il peut se connecter avant suspension
        $preSuspend = $this->postJson($client, '/api/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);
        $this->assertSame(Response::HTTP_OK, $preSuspend->getStatusCode());

        // Suspendre
        $suspendResp = $this->patchJson(
            $client,
            '/api/admin/users/' . $target->getId()->toRfc4122() . '/suspend',
            [],
            $token,
        );
        $this->assertSame(Response::HTTP_OK, $suspendResp->getStatusCode());

        // Le login doit désormais répondre 403 avec le message attendu
        $loginResp = $this->postJson($client, '/api/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);
        $this->assertSame(Response::HTTP_FORBIDDEN, $loginResp->getStatusCode());
        $body = json_decode($loginResp->getContent(), true);
        $this->assertStringContainsString('suspendu', strtolower($body['message']));
    }

    public function testActivateRestoresLogin(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        $email = 'activate_' . Uuid::v4()->toRfc4122() . '@cinaf-test.com';
        $password = 'Password123!';
        $target = $this->createUser($email, ['ROLE_USER'], $password);

        // Suspendre puis réactiver
        $this->patchJson($client, '/api/admin/users/' . $target->getId()->toRfc4122() . '/suspend', [], $token);
        $activateResp = $this->patchJson($client, '/api/admin/users/' . $target->getId()->toRfc4122() . '/activate', [], $token);

        $body = $this->assertJsonResponse($activateResp, Response::HTTP_OK);
        $this->assertFalse($body['isSuspended']);

        // Le login fonctionne à nouveau
        $loginResp = $this->postJson($client, '/api/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);
        $this->assertSame(Response::HTTP_OK, $loginResp->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // PATCH role
    // -----------------------------------------------------------------------

    public function testUpdateRoleValid(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        $target = $this->createUser('promote_' . Uuid::v4()->toRfc4122() . '@cinaf-test.com', ['ROLE_USER']);

        $response = $this->patchJson(
            $client,
            '/api/admin/users/' . $target->getId()->toRfc4122() . '/role',
            ['roles' => ['ROLE_MODERATEUR']],
            $token,
        );

        $body = $this->assertJsonResponse($response, Response::HTTP_OK);
        $this->assertContains('ROLE_MODERATEUR', $body['roles']);
    }

    public function testUpdateRoleInvalidRejected(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        $target = $this->createUser('hacker_' . Uuid::v4()->toRfc4122() . '@cinaf-test.com', ['ROLE_USER']);

        $response = $this->patchJson(
            $client,
            '/api/admin/users/' . $target->getId()->toRfc4122() . '/role',
            ['roles' => ['ROLE_HACKER']],
            $token,
        );

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testAdminCannotRemoveOwnAdminRole(): void
    {
        [$client, $token, $admin] = $this->createAuthenticatedClient('ROLE_ADMIN');

        $response = $this->patchJson(
            $client,
            '/api/admin/users/' . $admin->getId()->toRfc4122() . '/role',
            ['roles' => ['ROLE_USER']],
            $token,
        );

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // Export CSV
    // -----------------------------------------------------------------------

    public function testExportReturnsCsv(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        $client->request(
            'GET',
            '/api/admin/users/export',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );
        $response = $client->getResponse();

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('users-export.csv', (string) $response->headers->get('Content-Disposition'));

        $content = $response->getContent();
        if ($content === false) {
            // StreamedResponse — capturer via output buffering
            ob_start();
            $response->sendContent();
            $content = ob_get_clean();
        }
        $this->assertStringContainsString('email', $content);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function createUser(string $email, array $roles, string $password = 'Password123!'): User
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setRoles($roles);
        $user->setIsVerified(true);
        $user->setPassword($hasher->hashPassword($user, $password));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
