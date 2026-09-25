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
 * Tests fonctionnels de GET /api/subscriptions/payments — historique invoices Stripe.
 *
 * STRIPE_ENABLED=false en env test : le cas « client Stripe existant »
 * instancie donc le contrôleur à la main avec un StripeService mocké (aucun
 * appel réseau) ; les autres cas passent par le client HTTP.
 * Lancement : `php bin/phpunit tests/Controller/SubscriptionPaymentsControllerTest.php`.
 */
final class SubscriptionPaymentsControllerTest extends ApiTestCase
{
    /**
     * Utilisateur ayant un stripeCustomerId : listInvoices() est appelé une fois avec
     * cet identifiant et ses factures sont renvoyées telles quelles (200).
     */
    public function testPaymentsReturns200WithArrayForUserWithStripeCustomer(): void
    {
        // Stripe désactivé en env test → on instancie le contrôleur avec un mock
        // + stripeEnabled=true pour vérifier le mapping DTO de bout en bout.
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        [$user, ] = $this->createUserWithToken($em);
        $plan = $this->seedPlan($em);
        $sub = $this->seedActiveSubscription($em, $user, $plan);
        $sub->setStripeCustomerId('cus_paym_'.uniqid());
        $em->flush();

        $expectedDtos = [
            [
                'id' => 'in_test_1',
                'paidAt' => (new \DateTimeImmutable('-1 day'))->format(\DateTimeInterface::ATOM),
                'amount' => 999,
                'currency' => 'EUR',
                'status' => 'paid',
                'planName' => 'Mensuel',
                'invoicePdfUrl' => 'https://stripe.com/pdf/in_test_1.pdf',
            ],
        ];

        $stripeMock = $this->createMock(StripeService::class);
        $stripeMock->expects($this->once())
            ->method('listInvoices')
            ->with($sub->getStripeCustomerId())
            ->willReturn($expectedDtos);

        $controller = new SubscriptionController(
            $container->get('App\\Repository\\SubscriptionRepository'),
            $container->get('App\\Repository\\SubscriptionPlanRepository'),
            $container->get(SubscriptionService::class),
            $stripeMock,
            stripeEnabled: true,
            logger: new \Psr\Log\NullLogger(),
        );
        $controller->setContainer($container);

        $tokenStorage = $container->get('security.token_storage');
        $tokenStorage->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken(
            $user,
            'main',
            $user->getRoles(),
        ));

        $response = $controller->payments();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertCount(1, $body);
        $this->assertSame('in_test_1', $body[0]['id']);
        $this->assertSame(999, $body[0]['amount']);
        $this->assertSame('EUR', $body[0]['currency']);
        $this->assertSame('paid', $body[0]['status']);
    }

    /** Utilisateur connecté jamais passé par Stripe (et Stripe désactivé) → 200 avec un tableau vide. */
    public function testPaymentsReturnsEmptyArrayWhenNoStripeCustomerId(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [, $token] = $this->createUserWithToken($em);

        $response = $this->getJson($client, '/api/subscriptions/payments', $token);
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);
        $this->assertSame([], $body);
    }

    /** Appel sans JWT → 401. */
    public function testPaymentsReturns401ForAnonymous(): void
    {
        $client = static::createClient();
        $response = $this->getJson($client, '/api/subscriptions/payments');
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /**
     * Persiste un utilisateur ROLE_USER (email unique) et forge son JWT sans passer
     * par `/api/auth/login`.
     *
     * @return array{0:User,1:string}
     */
    private function createUserWithToken(EntityManagerInterface $em): array
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        /** @var JWTTokenManagerInterface $jwt */
        $jwt = static::getContainer()->get(JWTTokenManagerInterface::class);

        $user = new User();
        $user->setEmail('payments_'.Uuid::v4()->toRfc4122().'@cinaf-test.com');
        $user->setFirstName('Payments');
        $user->setLastName('Test');
        $user->setRoles(['ROLE_USER']);
        $user->setIsVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'Password123!'));
        $em->persist($user);
        $em->flush();

        return [$user, $jwt->create($user)];
    }

    /** Persiste un plan mensuel actif au nom unique. */
    private function seedPlan(EntityManagerInterface $em): SubscriptionPlan
    {
        $plan = new SubscriptionPlan();
        $plan->setName('PaymentsTest-'.uniqid());
        $plan->setDescription('Plan de test pour invoices.');
        $plan->setPriceCents(999);
        $plan->setIntervalUnit(SubscriptionPlan::INTERVAL_MONTH);
        $plan->setIntervalCount(1);
        $plan->setFeatures(['Test']);
        $plan->setIsActive(true);
        $em->persist($plan);
        $em->flush();
        return $plan;
    }

    /** Persiste un abonnement ACTIVE (début il y a 2 jours, fin dans 25 jours), sans client Stripe. */
    private function seedActiveSubscription(
        EntityManagerInterface $em,
        User $user,
        SubscriptionPlan $plan,
    ): Subscription {
        $sub = new Subscription();
        $sub->setUser($user);
        $sub->setPlan($plan);
        $sub->setStatus(Subscription::STATUS_ACTIVE);
        $sub->setStartsAt(new \DateTimeImmutable('-2 days'));
        $sub->setEndsAt(new \DateTimeImmutable('+25 days'));
        $em->persist($sub);
        $em->flush();
        return $sub;
    }
}
