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
 * Tests fonctionnels des endpoints admin de résiliation différée.
 *
 * En env test, STRIPE_ENABLED=false donc le contrôleur saute l'appel Stripe
 * et applique uniquement la mise à jour locale. La sémantique attendue
 * (status reste ACTIVE, canceledAt rempli, endsAt préservé) reste vérifiable
 * par cette voie.
 */
final class AdminSubscriptionCancelTest extends ApiTestCase
{
    public function testAdminCancelKeepsStatusActiveAndFillsCanceledAt(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$user] = $this->createUser($em, 'admin_cancel_user_');
        [, $adminToken] = $this->createAdminWithToken($em);
        $plan = $this->seedPlan($em);
        $sub = $this->seedActiveSubscription($em, $user, $plan, endsInDays: 25);

        // L'endpoint admin attend l'id du USER, pas du subscription.
        $response = $this->deleteJson(
            $client,
            sprintf('/api/admin/users/%s/subscription', $user->getId()->toRfc4122()),
            $adminToken
        );
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        // Comportement métier : status reste ACTIVE, canceledAt rempli, endsAt préservé.
        $em->clear();
        $reloaded = $em->getRepository(Subscription::class)->find($sub->getId());
        $this->assertSame(Subscription::STATUS_ACTIVE, $reloaded->getStatus());
        $this->assertNotNull($reloaded->getCanceledAt());
        $this->assertNotNull($reloaded->getEndsAt());
        $this->assertGreaterThan(new \DateTimeImmutable(), $reloaded->getEndsAt());

        // L'utilisateur conserve l'accès tant que la période n'est pas finie.
        $this->assertTrue($reloaded->isCurrentlyActive());

        // Le body renvoie le sub à jour.
        $this->assertSame('ACTIVE', $body['subscription']['status']);
        $this->assertNotNull($body['subscription']['canceledAt']);
    }

    public function testAdminCancelReturns403ForNonAdmin(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$victim] = $this->createUser($em, 'victim_');
        // Création d'un utilisateur lambda (ROLE_USER) qui tente d'utiliser l'endpoint admin.
        [, $userToken] = $this->createUserWithToken($em, 'attacker_');

        $response = $this->deleteJson(
            $client,
            sprintf('/api/admin/users/%s/subscription', $victim->getId()->toRfc4122()),
            $userToken
        );

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testAdminResumeUndoesScheduledCancellation(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$user] = $this->createUser($em, 'admin_resume_user_');
        [, $adminToken] = $this->createAdminWithToken($em);
        $plan = $this->seedPlan($em);
        $sub = $this->seedActiveSubscription($em, $user, $plan, endsInDays: 18);

        // Étape 1 — l'admin résilie.
        $cancelResp = $this->deleteJson(
            $client,
            sprintf('/api/admin/users/%s/subscription', $user->getId()->toRfc4122()),
            $adminToken
        );
        $this->assertJsonResponse($cancelResp, Response::HTTP_OK);

        // Étape 2 — l'admin réactive avant la fin de période.
        $resumeResp = $this->postJson(
            $client,
            sprintf('/api/admin/users/%s/subscription/resume', $user->getId()->toRfc4122()),
            [],
            $adminToken
        );
        $this->assertJsonResponse($resumeResp, Response::HTTP_OK);

        // Vérification : canceledAt est de nouveau null, status toujours ACTIVE.
        $em->clear();
        $reloaded = $em->getRepository(Subscription::class)->find($sub->getId());
        $this->assertSame(Subscription::STATUS_ACTIVE, $reloaded->getStatus());
        $this->assertNull($reloaded->getCanceledAt());
    }

    // ----------------------------------------------------------------
    // Helpers (dupliqués du fichier user — préfère cette duplication
    // locale à un partage via trait pour conserver l'isolation des tests).
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

    private function seedPlan(EntityManagerInterface $em): SubscriptionPlan
    {
        $plan = new SubscriptionPlan();
        $plan->setName('AdminCancelTest-'.uniqid());
        $plan->setDescription('Plan de test pour résiliation différée admin.');
        $plan->setPriceCents(999);
        $plan->setIntervalUnit(SubscriptionPlan::INTERVAL_MONTH);
        $plan->setIntervalCount(1);
        $plan->setFeatures(['Test']);
        $plan->setIsActive(true);
        $em->persist($plan);
        $em->flush();
        return $plan;
    }

    private function seedActiveSubscription(
        EntityManagerInterface $em,
        User $user,
        SubscriptionPlan $plan,
        int $endsInDays,
    ): Subscription {
        $sub = new Subscription();
        $sub->setUser($user);
        $sub->setPlan($plan);
        $sub->setStatus(Subscription::STATUS_ACTIVE);
        $sub->setStartsAt(new \DateTimeImmutable('-2 days'));
        $sub->setEndsAt(new \DateTimeImmutable(sprintf('+%d days', $endsInDays)));
        $em->persist($sub);
        $em->flush();
        return $sub;
    }
}
