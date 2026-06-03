<?php

namespace App\Tests\Admin\Controller;

use App\Entity\Subscription;
use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Tests fonctionnels de GET /api/admin/users/{id}/payments.
 *
 * En env test STRIPE_ENABLED=false → l'endpoint renvoie `[]` sans frapper
 * Stripe (le wrapper du contrôleur swallow l'absence du flag). On vérifie
 * donc surtout la chaîne d'auth/auto et le contrat de réponse (tableau JSON).
 */
final class AdminUserPaymentsTest extends ApiTestCase
{
    public function testAdminCanFetchPaymentsForUserWithoutSubscriptionReturnsEmptyArray(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // L'admin consulte les paiements d'un user qui n'a aucun abonnement →
        // pas de stripeCustomerId, donc le contrat est `[]` (et pas une 404).
        [$user] = $this->createUser($em, 'payments_target_');
        [, $adminToken] = $this->createAdminWithToken($em);

        $response = $this->getJson(
            $client,
            sprintf('/api/admin/users/%s/payments', $user->getId()->toRfc4122()),
            $adminToken
        );

        $body = $this->assertJsonResponse($response, Response::HTTP_OK);
        $this->assertIsArray($body);
        $this->assertSame([], $body);
    }

    public function testAdminPaymentsReturns404ForUnknownUser(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [, $adminToken] = $this->createAdminWithToken($em);
        $unknownId = Uuid::v4()->toRfc4122();

        $response = $this->getJson(
            $client,
            sprintf('/api/admin/users/%s/payments', $unknownId),
            $adminToken
        );

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testAdminPaymentsReturns403ForNonAdmin(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // Un user lambda ne doit pas pouvoir lire les paiements d'un autre user
        // via l'endpoint admin (firewall ^/api/admin → ROLE_ADMIN).
        [$victim] = $this->createUser($em, 'victim_payments_');
        [, $attackerToken] = $this->createUserWithToken($em, 'attacker_payments_');

        $response = $this->getJson(
            $client,
            sprintf('/api/admin/users/%s/payments', $victim->getId()->toRfc4122()),
            $attackerToken
        );

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    // ----------------------------------------------------------------
    // Helpers (mêmes que dans AdminSubscriptionCancelTest, dupliqués
    // volontairement pour conserver l'autonomie de chaque test class).
    // ----------------------------------------------------------------

    /**
     * @return array{0:User,1:string}
     */
    private function createAdminWithToken(EntityManagerInterface $em): array
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        /** @var JWTTokenManagerInterface $jwt */
        $jwt = static::getContainer()->get(JWTTokenManagerInterface::class);

        $admin = new User();
        $admin->setEmail('admin_'.Uuid::v4()->toRfc4122().'@cinaf-test.com');
        $admin->setFirstName('Admin');
        $admin->setLastName('Test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setIsVerified(true);
        $admin->setPassword($hasher->hashPassword($admin, 'Password123!'));
        $em->persist($admin);
        $em->flush();

        return [$admin, $jwt->create($admin)];
    }

    /**
     * @return array{0:User,1:string}
     */
    private function createUserWithToken(EntityManagerInterface $em, string $emailPrefix = 'user_'): array
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        /** @var JWTTokenManagerInterface $jwt */
        $jwt = static::getContainer()->get(JWTTokenManagerInterface::class);

        $user = new User();
        $user->setEmail($emailPrefix.Uuid::v4()->toRfc4122().'@cinaf-test.com');
        $user->setFirstName('User');
        $user->setLastName('Test');
        $user->setRoles(['ROLE_USER']);
        $user->setIsVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'Password123!'));
        $em->persist($user);
        $em->flush();

        return [$user, $jwt->create($user)];
    }

    /**
     * @return array{0:User}
     */
    private function createUser(EntityManagerInterface $em, string $emailPrefix): array
    {
        [$u] = $this->createUserWithToken($em, $emailPrefix);
        return [$u];
    }
}
