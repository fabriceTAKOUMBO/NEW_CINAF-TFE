<?php

namespace App\Tests\Studio\Controller;

use App\Entity\User;
use App\Tests\Studio\Support\StudioTestTrait;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

class StudioMeControllerTest extends ApiTestCase
{
    use StudioTestTrait;

    public function testGetMeReturnsStudioWithStats(): void
    {
        $client = static::createClient();
        [, $studio, $token] = $this->createCreatorWithStudio();

        $resp = $this->getJson($client, '/api/studio/me', $token);
        $body = $this->assertJsonResponse($resp, Response::HTTP_OK);

        $this->assertArrayHasKey('studio', $body);
        $this->assertArrayHasKey('stats', $body);
        $this->assertSame($studio->getId()->toRfc4122(), $body['studio']['id']);
        foreach ([
            'totalFilms', 'publishedFilms', 'draftFilms', 'withdrawnFilms',
            'totalSeries', 'publishedSeries', 'draftSeries', 'withdrawnSeries',
            'pendingWithdrawals',
        ] as $key) {
            $this->assertArrayHasKey($key, $body['stats']);
            $this->assertIsInt($body['stats'][$key]);
        }
    }

    public function testGetMeUserWithoutStudio403(): void
    {
        $client = static::createClient();

        // Créer un user ROLE_CREATEUR sans studio associé
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        /** @var JWTTokenManagerInterface $jwt */
        $jwt = static::getContainer()->get(JWTTokenManagerInterface::class);

        $user = new User();
        $user->setEmail('orphan_' . Uuid::v4()->toRfc4122() . '@cinaf-test.com');
        $user->setFirstName('No');
        $user->setLastName('Studio');
        $user->setRoles(['ROLE_CREATEUR']);
        $user->setIsVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'Password123!'));
        $em->persist($user);
        $em->flush();

        $token = $jwt->create($user);

        $resp = $this->getJson($client, '/api/studio/me', $token);
        $this->assertSame(Response::HTTP_FORBIDDEN, $resp->getStatusCode());
    }


    public function testUpdateNameAndDescriptionWithCreateurReturns200(): void
    {
        $client = static::createClient();
        [, , $token] = $this->createCreatorWithStudio();

        $resp = $this->patchJson($client, '/api/studio/me', [
            'name' => 'Studio Renommé ' . bin2hex(random_bytes(3)),
            'description' => 'Nouvelle description du studio.',
        ], $token);

        $body = $this->assertJsonResponse($resp, Response::HTTP_OK);
        $this->assertStringStartsWith('Studio Renommé', $body['name']);
        $this->assertSame('Nouvelle description du studio.', $body['description']);
    }

    public function testUpdateNameOnlyReturns200(): void
    {
        $client = static::createClient();
        [, $studio, $token] = $this->createCreatorWithStudio();
        $originalDescription = $studio->getDescription();

        $resp = $this->patchJson($client, '/api/studio/me', [
            'name' => 'Renommé Seul ' . bin2hex(random_bytes(3)),
        ], $token);

        $body = $this->assertJsonResponse($resp, Response::HTTP_OK);
        $this->assertStringStartsWith('Renommé Seul', $body['name']);
        $this->assertSame($originalDescription, $body['description']);
    }

    public function testUpdateWithEmptyNameReturns400(): void
    {
        $client = static::createClient();
        [, , $token] = $this->createCreatorWithStudio();

        $resp = $this->patchJson($client, '/api/studio/me', ['name' => '   '], $token);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $resp->getStatusCode());
    }

    public function testUpdateWithDuplicateNameReturns409(): void
    {
        $client = static::createClient();
        [, $other, ] = $this->createCreatorWithStudio('victim');
        [, , $token] = $this->createCreatorWithStudio('aggressor');

        $resp = $this->patchJson($client, '/api/studio/me', [
            'name' => $other->getName(),
        ], $token);

        $this->assertSame(Response::HTTP_CONFLICT, $resp->getStatusCode());
    }

    public function testUpdateWithoutFieldsReturns400(): void
    {
        $client = static::createClient();
        [, , $token] = $this->createCreatorWithStudio();

        $resp = $this->patchJson($client, '/api/studio/me', [], $token);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $resp->getStatusCode());
    }

    public function testUpdateRefusesAnonymousUserWith401(): void
    {
        $client = static::createClient();

        $client->request('PATCH', '/api/studio/me', [], [], [
            'CONTENT_TYPE' => 'application/merge-patch+json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['name' => 'Tentative anonyme']));

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
    }

}
