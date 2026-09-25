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
 * En français : tests fonctionnels de App\Admin\Controller\AdminUserController
 * (gestion des comptes par l'administrateur), regroupés par section :
 *  - 401 sans jeton, puis 403 avec un jeton ROLE_USER : ROLE_ADMIN est exigé
 *    (`access_control` `^/api/admin` + `#[IsGranted]`) ;
 *  - liste paginée (recherche, filtre par rôle, rôle inconnu → 400) et fiche
 *    (identifiant non UUID → 400, UUID inconnu → 404) ;
 *  - suspension / réactivation, avec leur effet réel sur `POST /api/auth/login`
 *    (403 « compte suspendu », puis de nouveau 200) ;
 *  - remplacement des rôles (rôle hors liste blanche → 400) ;
 *  - export CSV.
 * Auto-protection vérifiée : un admin ne peut ni se suspendre, ni se retirer
 * ROLE_ADMIN (400). Non couverts dans ce fichier : `PATCH /{id}` (email,
 * prénom, nom, isVerified) et `DELETE /{id}`.
 *
 * Données : admins et utilisateurs cibles créés à la volée, avec des emails
 * uniques (préfixe + UUID) car la base n'est pas vidée entre les tests.
 *
 * Run: php bin/phpunit tests/Admin/Controller/AdminUserControllerTest.php
 */
class AdminUserControllerTest extends ApiTestCase
{
    // -----------------------------------------------------------------------
    // 401 — Sans token
    // -----------------------------------------------------------------------

    /** Liste sans jeton : 401 (authentification JWT requise). */
    public function testListRequiresAuth(): void
    {
        $client = static::createClient();
        $response = $this->getJson($client, '/api/admin/users');

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    /**
     * Fiche sans jeton : 401, avant même que le contrôleur ne cherche
     * l'utilisateur (l'UUID aléatoire n'a donc pas d'importance).
     */
    public function testGetOneRequiresAuth(): void
    {
        $client = static::createClient();
        $response = $this->getJson($client, '/api/admin/users/' . $this->randomUuid());

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    /** Suspension sans jeton : 401. */
    public function testSuspendRequiresAuth(): void
    {
        $client = static::createClient();
        // Requête brute : patchJson() exige un jeton, or on veut un PATCH anonyme.
        $headers = ['HTTP_ACCEPT' => 'application/json'];
        $client->request('PATCH', '/api/admin/users/' . $this->randomUuid() . '/suspend', [], [], $headers);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
    }

    /** Export CSV sans jeton : 401. */
    public function testExportRequiresAuth(): void
    {
        $client = static::createClient();
        $response = $this->getJson($client, '/api/admin/users/export');

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // 403 — Token ROLE_USER (non-admin)
    // -----------------------------------------------------------------------

    /** Liste avec un jeton ROLE_USER : 403 (authentifié, mais pas admin). */
    public function testListForbiddenForRoleUser(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_USER');
        $response = $this->getJson($client, '/api/admin/users', $token);

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    /**
     * Suspension avec un jeton ROLE_USER : 403, refusée avant le contrôleur
     * (l'UUID aléatoire n'est même pas recherché, d'où un 403 et non un 404).
     */
    public function testSuspendForbiddenForRoleUser(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_USER');
        $response = $this->patchJson($client, '/api/admin/users/' . $this->randomUuid() . '/suspend', [], $token);

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // 200 — Token ROLE_ADMIN
    // -----------------------------------------------------------------------

    /**
     * Liste en admin : 200, structure `{data, total, page, limit}` reprenant la
     * page et la limite demandées (1 et 10).
     */
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

    /**
     * Recherche `?search=findme` (sous-chaîne de l'email, du prénom ou du nom,
     * insensible à la casse) : l'utilisateur dont l'email commence par
     * `findme_` figure dans le résultat (200).
     */
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

    /**
     * Filtre `?role=ROLE_MODERATEUR` : chaque utilisateur renvoyé possède ce rôle
     * (200). NB : la présence du modérateur créé ici n'est pas vérifiée ; une
     * liste vide passerait aussi ce test.
     */
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

    /** Filtre sur un rôle hors liste blanche (`ROLE_HACKER`) : 400. */
    public function testListWithInvalidRoleFilterReturns400(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        $response = $this->getJson($client, '/api/admin/users?role=ROLE_HACKER', $token);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    /** Fiche d'un UUID bien formé mais inconnu : 404. */
    public function testGetOneNotFound(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        $response = $this->getJson($client, '/api/admin/users/' . $this->randomUuid(), $token);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    /** Fiche avec un identifiant qui n'est pas un UUID (`not-a-uuid`) : 400. */
    public function testGetOneInvalidUuidReturns400(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        $response = $this->getJson($client, '/api/admin/users/not-a-uuid', $token);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    /**
     * Fiche d'un utilisateur existant : 200, bon email, et champ `isSuspended`
     * présent et à false.
     */
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

    /**
     * Suspension d'un utilisateur : 200 avec `isSuspended: true`, et le drapeau
     * est bien enregistré en base.
     */
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
        // clear() vide l'identity map de Doctrine : find() relit la ligne en base
        // au lieu de renvoyer l'objet déjà en mémoire.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $em->getRepository(User::class)->find($target->getId());
        $this->assertTrue($reloaded->isSuspended());
    }

    /** Auto-protection : un admin qui tente de suspendre son propre compte reçoit 400. */
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

    /**
     * Effet réel de la suspension : l'utilisateur se connecte (200), l'admin le
     * suspend, puis `POST /api/auth/login` répond 403 avec un message contenant
     * « suspendu ».
     */
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

    /**
     * Réactivation : après suspension puis `PATCH /{id}/activate`, 200 avec
     * `isSuspended: false`, et la connexion fonctionne de nouveau (200).
     */
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

    /**
     * Remplacement des rôles par `[ROLE_MODERATEUR]` : 200 et le rôle figure dans
     * la réponse.
     */
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

    /** Attribution d'un rôle hors liste blanche (`ROLE_HACKER`) : 400. */
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

    /**
     * Auto-protection : un admin qui remplace ses propres rôles par
     * `[ROLE_USER]` (donc sans ROLE_ADMIN) reçoit 400.
     */
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

    /**
     * Export CSV : 200, type `text/csv`, pièce jointe `users-export.csv`, et le
     * contenu contient `email` (au moins l'en-tête de colonne).
     */
    public function testExportReturnsCsv(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        // Requête brute, sans en-tête Accept JSON : on attend un fichier CSV.
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
            // (branche de repli : export() renvoie aujourd'hui une Response
            // classique, dont getContent() fournit directement le CSV ; ce bloc
            // ne servirait que si l'endpoint repassait en StreamedResponse,
            // dont getContent() renvoie false).
            ob_start();
            $response->sendContent();
            $content = ob_get_clean();
        }
        $this->assertStringContainsString('email', $content);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Persiste un utilisateur vérifié avec les rôles donnés et un mot de passe
     * réellement haché (`Password123!` par défaut) : il peut donc se connecter
     * via `POST /api/auth/login`. Aucun jeton n'est forgé.
     */
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
