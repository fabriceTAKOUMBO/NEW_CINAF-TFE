<?php

namespace App\Tests\E2E;

use App\Entity\Studio;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Test E2E du parcours self-service "Je suis producteur" :
 *
 *  1. Un utilisateur logué (ROLE_USER) POST /api/studio/onboarding → studio créé,
 *     isValidated=false, ROLE_CREATEUR ajouté.
 *  2. POST /api/studio/films → film en DRAFT.
 *  3. POST /api/studio/films/{id}/publish → film passe en PENDING_APPROVAL
 *     (et NON en PUBLISHED, parce que le studio n'est pas encore validé).
 *  4. PATCH /api/studio/films/{id} → 409 (gel pendant l'attente d'approbation).
 *  5. GET /api/admin/approvals → l'admin voit le film dans la liste.
 *  6. PATCH /api/admin/films/{id}/approve → film publié + studio validé.
 *  7. POST /api/studio/films → 2e film en DRAFT.
 *  8. POST /api/studio/films/{id}/publish → cette fois le film part direct
 *     en PUBLISHED (le studio est désormais validé).
 */
class StudioOnboardingE2ETest extends ApiTestCase
{
    public function testFullOnboardingScenario(): void
    {
        $client = static::createClient();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        /** @var JWTTokenManagerInterface $jwt */
        $jwt = static::getContainer()->get(JWTTokenManagerInterface::class);

        // Étape 1 : un user "lambda" (ROLE_USER) se logue et crée son studio.
        $unique = bin2hex(random_bytes(4));
        $userEntity = new User();
        $userEntity->setEmail('onboarding-' . $unique . '@cinaf-test.com');
        $userEntity->setFirstName('Onboarding');
        $userEntity->setLastName('User');
        $userEntity->setRoles(['ROLE_USER']);
        $userEntity->setIsVerified(true);
        $userEntity->setPassword($hasher->hashPassword($userEntity, 'Password123!'));
        $em->persist($userEntity);
        $em->flush();
        $userToken = $jwt->create($userEntity);

        $studioName = 'Onboarding Studio ' . $unique;
        $resp = $this->postJson($client, '/api/studio/onboarding', [
            'name' => $studioName,
            'description' => 'Studio créé par un user en self-service.',
        ], $userToken);
        $studioBody = $this->assertJsonResponse($resp, Response::HTTP_CREATED);
        $this->assertFalse($studioBody['isValidated']);
        $studioId = $studioBody['id'];

        // Le user a maintenant ROLE_CREATEUR : on régénère son token pour
        // intégrer le nouveau rôle au JWT (sinon les calls /api/studio/*
        // sont refusés par access_control).
        $em->clear();
        $userEntity = $em->getRepository(User::class)->find($userEntity->getId());
        $this->assertContains('ROLE_CREATEUR', $userEntity->getRoles());
        $creatorToken = $jwt->create($userEntity);

        // Étape 2 : création d'un film en DRAFT.
        $resp = $this->postJson($client, '/api/studio/films', [
            'title' => 'Premier Film ' . $unique,
            'synopsis' => 'Premier contenu d\'un studio non validé.',
            'year' => 2024,
            'duration' => 90,
        ], $creatorToken);
        $film = $this->assertJsonResponse($resp, Response::HTTP_CREATED);
        $this->assertSame('DRAFT', $film['status']);
        $filmId = $film['id'];

        // Étape 3 : publish → PENDING_APPROVAL.
        $resp = $this->postJson($client, '/api/studio/films/' . $filmId . '/publish', [], $creatorToken);
        $published = $this->assertJsonResponse($resp, Response::HTTP_OK);
        $this->assertSame('PENDING_APPROVAL', $published['status']);

        // Étape 4 : édition pendant l'attente → 409.
        $resp = $this->patchJson(
            $client,
            '/api/studio/films/' . $filmId,
            ['title' => 'Tentative de modification'],
            $creatorToken,
        );
        $this->assertSame(Response::HTTP_CONFLICT, $resp->getStatusCode());

        // Étape 5 : l'admin liste les approbations.
        $admin = new User();
        $admin->setEmail('e2e-admin-' . $unique . '@cinaf-test.com');
        $admin->setFirstName('E2E');
        $admin->setLastName('Admin');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setIsVerified(true);
        $admin->setPassword($hasher->hashPassword($admin, 'Admin1234!'));
        $em->persist($admin);
        $em->flush();
        $adminToken = $jwt->create($admin);

        $resp = $this->getJson($client, '/api/admin/approvals', $adminToken);
        $list = $this->assertJsonResponse($resp, Response::HTTP_OK);
        $ids = array_column($list['data'], 'id');
        $this->assertContains($filmId, $ids);

        // Étape 6 : approbation → PUBLISHED + studio validé.
        $resp = $this->patchJson(
            $client,
            '/api/admin/films/' . $filmId . '/approve',
            [],
            $adminToken,
        );
        $approved = $this->assertJsonResponse($resp, Response::HTTP_OK);
        $this->assertSame('PUBLISHED', $approved['status']);

        $em->clear();
        $studio = $em->getRepository(Studio::class)->find(Uuid::fromString($studioId));
        $this->assertTrue($studio->isValidated());

        // Étape 7 : un 2e film.
        $resp = $this->postJson($client, '/api/studio/films', [
            'title' => 'Second Film ' . $unique,
            'synopsis' => 'Contenu post-validation.',
            'year' => 2024,
            'duration' => 95,
        ], $creatorToken);
        $film2 = $this->assertJsonResponse($resp, Response::HTTP_CREATED);
        $filmId2 = $film2['id'];

        // Étape 8 : publish → cette fois directement PUBLISHED.
        $resp = $this->postJson($client, '/api/studio/films/' . $filmId2 . '/publish', [], $creatorToken);
        $published2 = $this->assertJsonResponse($resp, Response::HTTP_OK);
        $this->assertSame('PUBLISHED', $published2['status']);
    }
}
