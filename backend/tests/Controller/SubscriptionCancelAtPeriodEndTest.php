<?php

namespace App\Tests\Controller;

use App\Controller\SubscriptionController;
use App\Entity\Subscription;
use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\Service\StripeService;
use App\Service\SubscriptionService;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Tests fonctionnels de POST /api/subscriptions/cancel — résiliation différée.
 *
 * En env test, STRIPE_ENABLED=false donc le bloc d'appel Stripe est sauté
 * dans le contrôleur HTTP. Le test « Stripe est appelé avec cancel_at_period_end »
 * instancie le contrôleur directement avec un mock + flag activé, pour vérifier
 * la primitive Stripe sans toucher au container global.
 */
final class SubscriptionCancelAtPeriodEndTest extends ApiTestCase
{
    public function testCancelKeepsStatusActiveAndFillsCanceledAt(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$user, $token] = $this->createUserWithToken($em);
        $plan = $this->seedPlan($em);
        $sub = $this->seedActiveSubscription($em, $user, $plan, endsInDays: 20);

        $response = $this->postJson($client, '/api/subscriptions/cancel', [], $token);
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        // Le contrat de la nouvelle règle métier : status reste ACTIVE,
        // canceledAt rempli, endsAt préservé.
        $em->clear();
        $reloaded = $em->getRepository(Subscription::class)->find($sub->getId());
        $this->assertSame(Subscription::STATUS_ACTIVE, $reloaded->getStatus());
        $this->assertNotNull($reloaded->getCanceledAt());
        $this->assertNotNull($reloaded->getEndsAt());
        $this->assertGreaterThan(new \DateTimeImmutable(), $reloaded->getEndsAt());

        // hasActiveSubscription doit rester true tant que endsAt n'est pas dépassé.
        $this->assertTrue($reloaded->isCurrentlyActive());

        // La réponse expose le sub à jour.
        $this->assertSame('ACTIVE', $body['subscription']['status']);
        $this->assertNotNull($body['subscription']['canceledAt']);
    }

    public function testCancelCallsStripeWithCancelAtPeriodEndTrue(): void
    {
        // STRIPE_ENABLED=false en env test → on ne peut pas tester le passage par
        // le container HTTP. On instancie donc le contrôleur directement avec un
        // StripeService mocké + stripeEnabled=true, et on vérifie que le mock
        // reçoit bien `cancelAtPeriodEnd($stripeSubscriptionId)`.
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        [$user, ] = $this->createUserWithToken($em);
        $plan = $this->seedPlan($em);
        $sub = $this->seedActiveSubscription($em, $user, $plan, endsInDays: 20);
        $sub->setStripeSubscriptionId('sub_under_test_'.uniqid());
        $em->flush();

        $stripeMock = $this->createMock(StripeService::class);
        $stripeMock->expects($this->once())
            ->method('cancelAtPeriodEnd')
            ->with($sub->getStripeSubscriptionId())
            ->willReturn(new \Stripe\Subscription($sub->getStripeSubscriptionId()));

        $controller = new SubscriptionController(
            $container->get('App\\Repository\\SubscriptionRepository'),
            $container->get('App\\Repository\\SubscriptionPlanRepository'),
            $container->get(SubscriptionService::class),
            $stripeMock,
            stripeEnabled: true,
            logger: new \Psr\Log\NullLogger(),
        );
        // setContainer() est nécessaire pour `$this->json()` et `$this->getUser()`.
        $controller->setContainer($container);

        // On force l'utilisateur courant via TokenStorage.
        $tokenStorage = $container->get('security.token_storage');
        $tokenStorage->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken(
            $user,
            'main',
            $user->getRoles(),
        ));

        $response = $controller->cancel();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testCancelReturns404IfNoActiveSubscription(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [, $token] = $this->createUserWithToken($em);

        $response = $this->postJson($client, '/api/subscriptions/cancel', [], $token);
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /**
     * @return array{0:User,1:string}
     */
    private function createUserWithToken(EntityManagerInterface $em): array
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        /** @var JWTTokenManagerInterface $jwt */
        $jwt = static::getContainer()->get(JWTTokenManagerInterface::class);

        $user = new User();
        $user->setEmail('cancel_'.Uuid::v4()->toRfc4122().'@cinaf-test.com');
        $user->setFirstName('Cancel');
        $user->setLastName('Test');
        $user->setRoles(['ROLE_USER']);
        $user->setIsVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'Password123!'));
        $em->persist($user);
        $em->flush();

        return [$user, $jwt->create($user)];
    }

    private function seedPlan(EntityManagerInterface $em): SubscriptionPlan
    {
        $plan = new SubscriptionPlan();
        $plan->setName('CancelTest-'.uniqid());
        $plan->setDescription('Plan de test pour résiliation différée.');
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
