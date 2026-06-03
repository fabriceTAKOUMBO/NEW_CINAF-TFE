<?php

namespace App\Tests\E2E;

use App\Entity\Film;
use App\Entity\Studio;
use App\Entity\User;
use App\Entity\WithdrawalRequest;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Phase H — Test e2e complet : du DRAFT producteur jusqu'au retrait approuvé
 * par l'admin.
 *
 * Force `CATALOGUE_SOURCE=db` pour vérifier que la bascule active fait bien
 * disparaître le film du catalogue public après retrait.
 *
 * Scénario complet (Phase H spec) :
 *  1. Setup : studio + producer
 *  2. Producer login → token JWT
 *  3. POST /api/studio/films → film DRAFT
 *  4. PATCH /api/studio/films/{id} avec poster + bunnyVideoId valide
 *  5. POST /api/studio/films/{id}/publish → status=PUBLISHED
 *  6. GET /api/catalogue/discover → film visible dans la liste publique
 *  7. POST /api/studio/films/{id}/withdraw → WithdrawalRequest PENDING
 *  8. Tentative second withdraw → 409
 *  9. Admin login → token JWT admin
 * 10. GET /api/admin/withdrawals?status=PENDING → liste contient le retrait
 * 11. POST /api/admin/withdrawals/{wrId}/approve → film passe WITHDRAWN
 * 12. GET /api/catalogue/discover → film disparu
 * 13. GET /api/admin/films/{id} → status=WITHDRAWN, withdrawnAt set
 */
class StudioToAdminWithdrawalE2ETest extends ApiTestCase
{
    protected function setUp(): void
    {
        // Phase F — force CATALOGUE_SOURCE=db pour ce test e2e (sinon le
        // /api/catalogue/discover lit Bunny et le scénario "film visible →
        // film disparu" ne se vérifie pas en DB).
        $_ENV['CATALOGUE_SOURCE'] = 'db';
        $_SERVER['CATALOGUE_SOURCE'] = 'db';
        putenv('CATALOGUE_SOURCE=db');
        parent::setUp();
    }

    public function testFullScenario(): void
    {
        $client = static::createClient();
        $this->cleanCatalogueTables();

        // 1. Setup : studio + producer + admin
        [$producerUser, $studio, $producerToken] = $this->seedProducerWithStudio();
        [, $adminToken] = $this->seedAdmin();

        // 3. POST /api/studio/films → 201 DRAFT
        $resp = $this->postJson($client, '/api/studio/films', [
            'title' => 'E2E Withdrawal Film',
            'synopsis' => 'Un film e2e qui sera retiré du catalogue.',
            'year' => 2024,
            'duration' => 100,
        ], $producerToken);
        $film = $this->assertJsonResponse($resp, Response::HTTP_CREATED);
        $this->assertSame('DRAFT', $film['status']);
        $filmId = $film['id'];

        // 4. PATCH avec poster + bunnyVideoId valide (path studios/{slug}/...)
        $bunnyPath = sprintf('studios/%s/videos/master.mp4', $studio->getSlug());
        $resp = $this->patchJson($client, '/api/studio/films/' . $filmId, [
            'poster' => 'https://cdn.example/poster.jpg',
            'bunnyVideoId' => $bunnyPath,
        ], $producerToken);
        $body = $this->assertJsonResponse($resp, Response::HTTP_OK);
        $this->assertSame($bunnyPath, $body['bunnyVideoId']);
        $this->assertSame('https://cdn.example/poster.jpg', $body['poster']);

        // 5. POST /api/studio/films/{id}/publish
        $client->request('POST', '/api/studio/films/' . $filmId . '/publish', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $producerToken,
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $body = $this->assertJsonResponse($client->getResponse(), Response::HTTP_OK);
        $this->assertSame('PUBLISHED', $body['status']);
        $this->assertNotNull($body['publishedAt']);

        // 6. GET /api/catalogue/discover → film visible (avec CATALOGUE_SOURCE=db)
        $resp = $this->getJson($client, '/api/catalogue/discover?limit=50');
        $body = $this->assertJsonResponse($resp, Response::HTTP_OK);
        $titles = array_column($body['data'], 'title');
        $this->assertContains('E2E Withdrawal Film', $titles, 'Le film publié doit apparaître dans /api/catalogue/discover');

        // 7. POST /api/studio/films/{id}/withdraw → 201 PENDING
        $resp = $this->postJson($client, '/api/studio/films/' . $filmId . '/withdraw', [
            'reason' => 'E2E — retrait demandé par le producteur',
        ], $producerToken);
        $withdrawal = $this->assertJsonResponse($resp, Response::HTTP_CREATED);
        $this->assertSame(WithdrawalRequest::STATUS_PENDING, $withdrawal['status']);
        $this->assertSame('film', $withdrawal['targetType']);
        $this->assertSame($filmId, $withdrawal['targetId']);
        $withdrawalId = $withdrawal['id'];

        // 8. Tentative de second withdraw → 409 (contrainte unique partielle)
        $resp = $this->postJson($client, '/api/studio/films/' . $filmId . '/withdraw', [
            'reason' => 'Doublon',
        ], $producerToken);
        $this->assertSame(Response::HTTP_CONFLICT, $resp->getStatusCode());

        // 10. GET /api/admin/withdrawals?status=PENDING (admin)
        $resp = $this->getJson($client, '/api/admin/withdrawals?status=PENDING', $adminToken);
        $body = $this->assertJsonResponse($resp, Response::HTTP_OK);
        $ids = array_column($body['data'] ?? [], 'id');
        $this->assertContains($withdrawalId, $ids, 'Le retrait PENDING doit apparaître dans la liste admin');

        // 11. POST /api/admin/withdrawals/{wrId}/approve
        // Réponse: {withdrawal: {...}, target: {...}} (cf. AdminWithdrawalController::approve)
        $resp = $this->postJson(
            $client,
            '/api/admin/withdrawals/' . $withdrawalId . '/approve',
            ['reviewNote' => 'Approuvé E2E'],
            $adminToken,
        );
        $body = $this->assertJsonResponse($resp, Response::HTTP_OK);
        $this->assertArrayHasKey('withdrawal', $body);
        $this->assertSame('APPROVED', $body['withdrawal']['status']);
        $this->assertArrayHasKey('target', $body);
        $this->assertSame('WITHDRAWN', $body['target']['status']);

        // 12. GET /api/catalogue/discover → film disparu
        $resp = $this->getJson($client, '/api/catalogue/discover?limit=50');
        $body = $this->assertJsonResponse($resp, Response::HTTP_OK);
        $titles = array_column($body['data'], 'title');
        $this->assertNotContains('E2E Withdrawal Film', $titles, 'Le film WITHDRAWN ne doit plus être listé publiquement');

        // 13. GET /api/admin/films/{id} → status=WITHDRAWN, withdrawnAt set
        $resp = $this->getJson($client, '/api/admin/films/' . $filmId, $adminToken);
        $body = $this->assertJsonResponse($resp, Response::HTTP_OK);
        $this->assertSame('WITHDRAWN', $body['status']);
        $this->assertNotNull($body['withdrawnAt'], 'withdrawnAt doit être renseigné');
    }

    // -----------------------------------------------------------------------
    // Helpers spécifiques à ce test
    // -----------------------------------------------------------------------

    /**
     * @return array{0: User, 1: Studio, 2: string}
     */
    private function seedProducerWithStudio(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        /** @var JWTTokenManagerInterface $jwt */
        $jwt = static::getContainer()->get(JWTTokenManagerInterface::class);

        $unique = bin2hex(random_bytes(4));
        $user = new User();
        $user->setEmail('e2e-prod-' . $unique . '@cinaf-test.com');
        $user->setFirstName('E2E');
        $user->setLastName('Producer');
        $user->setRoles(['ROLE_CREATEUR']);
        $user->setIsVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'Producer1234!'));
        $em->persist($user);

        $studio = new Studio();
        $studio->setName('E2E Studio ' . $unique);
        $studio->setSlug('e2e-studio-' . $unique);
        $studio->setOwner($user);
        $studio->setBunnyFolder('studios/e2e-studio-' . $unique . '/');
        $studio->setIsActive(true);
        // Scénario "studio historique" : déjà validé → publish direct
        // en PUBLISHED, non-régression du workflow withdrawal.
        $studio->setIsValidated(true);
        $em->persist($studio);
        $em->flush();

        return [$user, $studio, $jwt->create($user)];
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function seedAdmin(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        /** @var JWTTokenManagerInterface $jwt */
        $jwt = static::getContainer()->get(JWTTokenManagerInterface::class);

        $admin = new User();
        $admin->setEmail('e2e-admin-' . bin2hex(random_bytes(3)) . '@cinaf-test.com');
        $admin->setFirstName('E2E');
        $admin->setLastName('Admin');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setIsVerified(true);
        $admin->setPassword($hasher->hashPassword($admin, 'Admin1234!'));
        $em->persist($admin);
        $em->flush();

        return [$admin, $jwt->create($admin)];
    }

    private function cleanCatalogueTables(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $conn = $em->getConnection();
        $conn->executeStatement('DELETE FROM withdrawal_request');
        $conn->executeStatement('DELETE FROM episode');
        $conn->executeStatement('DELETE FROM season');
        $conn->executeStatement('DELETE FROM serie');
        $conn->executeStatement('DELETE FROM film');
        $em->clear();
    }
}
