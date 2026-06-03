<?php

namespace App\Tests\Admin\Controller;

use App\Entity\Film;
use App\Entity\Serie;
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
 * Tests fonctionnels du workflow d'approbation admin :
 *   - GET    /api/admin/approvals
 *   - PATCH  /api/admin/films/{id}/approve | reject
 *   - PATCH  /api/admin/series/{id}/approve | reject
 *
 * NB : tous les tests bootent le kernel une seule fois via `createClient()`
 * en début de test, puis instancient les entités via l'EM directement
 * (le kernel Symfony refuse un double-boot).
 */
class AdminApprovalControllerTest extends ApiTestCase
{
    use StudioTestTrait;

    public function testListReturnsPendingApprovals(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // Studio non validé + 1 film + 1 série en PENDING_APPROVAL.
        [, $studio, ] = $this->createCreatorWithStudio();
        $studio->setIsValidated(false);
        $em->flush();

        $this->createFilmInStudio($em, $studio, 'Film en attente', Film::STATUS_PENDING_APPROVAL);
        $this->createSerieInStudio($em, $studio, 'Série en attente', Serie::STATUS_PENDING_APPROVAL);

        $adminToken = $this->createAdminToken();

        $response = $this->getJson($client, '/api/admin/approvals', $adminToken);
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $this->assertPaginatedStructure($body);
        $this->assertGreaterThanOrEqual(2, $body['total']);

        $kinds = array_column($body['data'], 'kind');
        $this->assertContains('film', $kinds);
        $this->assertContains('serie', $kinds);

        // Au moins une entrée doit pointer sur le studio non validé
        // créé ci-dessus (la base de test peut contenir d'autres pending
        // créés par d'autres méthodes — on filtre).
        $myStudioRows = array_filter(
            $body['data'],
            fn (array $r) => isset($r['studio']['id']) && $r['studio']['id'] === $studio->getId()->toRfc4122(),
        );
        $this->assertGreaterThanOrEqual(2, \count($myStudioRows));
        foreach ($myStudioRows as $row) {
            $this->assertFalse($row['studio']['isValidated']);
        }
    }

    public function testApproveFilmPublishesAndValidatesStudio(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [, $studio, ] = $this->createCreatorWithStudio();
        $studio->setIsValidated(false);
        $em->flush();

        $film = $this->createFilmInStudio($em, $studio, 'À approuver', Film::STATUS_PENDING_APPROVAL);

        $adminToken = $this->createAdminToken();

        $response = $this->patchJson(
            $client,
            '/api/admin/films/' . $film->getId()->toRfc4122() . '/approve',
            [],
            $adminToken,
        );
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);
        $this->assertSame('PUBLISHED', $body['status']);

        $em->clear();
        $refreshedStudio = $em->getRepository(Studio::class)->find($studio->getId());
        $this->assertTrue($refreshedStudio->isValidated());
    }

    public function testApproveFilmAlreadyPublishedReturns409(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [, $studio, ] = $this->createCreatorWithStudio();
        $film = $this->createFilmInStudio($em, $studio, 'Déjà publié', Film::STATUS_PUBLISHED);

        $adminToken = $this->createAdminToken();

        $response = $this->patchJson(
            $client,
            '/api/admin/films/' . $film->getId()->toRfc4122() . '/approve',
            [],
            $adminToken,
        );
        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    public function testRejectFilmReturnsToDraft(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [, $studio, ] = $this->createCreatorWithStudio();
        $studio->setIsValidated(false);
        $em->flush();

        $film = $this->createFilmInStudio($em, $studio, 'À refuser', Film::STATUS_PENDING_APPROVAL);

        $adminToken = $this->createAdminToken();

        $response = $this->patchJson(
            $client,
            '/api/admin/films/' . $film->getId()->toRfc4122() . '/reject',
            ['reason' => 'Métadonnées insuffisantes.'],
            $adminToken,
        );
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);
        $this->assertSame('DRAFT', $body['status']);

        $em->clear();
        $refreshedStudio = $em->getRepository(Studio::class)->find($studio->getId());
        $this->assertFalse(
            $refreshedStudio->isValidated(),
            'Le refus ne doit pas valider le studio.',
        );
    }

    public function testApproveSerieAlsoValidatesStudio(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [, $studio, ] = $this->createCreatorWithStudio();
        $studio->setIsValidated(false);
        $em->flush();

        $serie = $this->createSerieInStudio($em, $studio, 'Série à approuver', Serie::STATUS_PENDING_APPROVAL);

        $adminToken = $this->createAdminToken();

        $response = $this->patchJson(
            $client,
            '/api/admin/series/' . $serie->getId()->toRfc4122() . '/approve',
            [],
            $adminToken,
        );
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);
        $this->assertSame('PUBLISHED', $body['status']);

        $em->clear();
        $refreshedStudio = $em->getRepository(Studio::class)->find($studio->getId());
        $this->assertTrue($refreshedStudio->isValidated());
    }

    public function testNonAdminCannotApprove403(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [, $studio, $creatorToken] = $this->createCreatorWithStudio();
        $film = $this->createFilmInStudio($em, $studio, 'Privé', Film::STATUS_PENDING_APPROVAL);

        $response = $this->patchJson(
            $client,
            '/api/admin/films/' . $film->getId()->toRfc4122() . '/approve',
            [],
            $creatorToken,
        );
        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function createAdminToken(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        /** @var JWTTokenManagerInterface $jwt */
        $jwt = static::getContainer()->get(JWTTokenManagerInterface::class);

        $admin = new User();
        $admin->setEmail('admin_' . Uuid::v4()->toRfc4122() . '@cinaf-test.com');
        $admin->setFirstName('Approval');
        $admin->setLastName('Admin');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setIsVerified(true);
        $admin->setPassword($hasher->hashPassword($admin, 'Password123!'));
        $em->persist($admin);
        $em->flush();

        return $jwt->create($admin);
    }

    private function createFilmInStudio(
        EntityManagerInterface $em,
        Studio $studio,
        string $title,
        string $status,
    ): Film {
        $film = new Film();
        $film->setTitle($title);
        $film->setSlug(strtolower(str_replace(' ', '-', $title)) . '-' . bin2hex(random_bytes(3)));
        $film->setSynopsis('Synopsis.');
        $film->setYear(2024);
        $film->setDuration(90);
        $film->setStudio($studio);
        $film->setStatus($status);
        $em->persist($film);
        $em->flush();

        return $film;
    }

    private function createSerieInStudio(
        EntityManagerInterface $em,
        Studio $studio,
        string $title,
        string $status,
    ): Serie {
        $serie = new Serie();
        $serie->setTitle($title);
        $serie->setSlug(strtolower(str_replace(' ', '-', $title)) . '-' . bin2hex(random_bytes(3)));
        $serie->setSynopsis('Synopsis série.');
        $serie->setYear(2024);
        $serie->setStudio($studio);
        $serie->setStatus($status);
        $em->persist($serie);
        $em->flush();

        return $serie;
    }
}
