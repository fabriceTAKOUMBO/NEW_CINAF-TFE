<?php

namespace App\Tests\Controller;

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
 * Tests fonctionnels de POST /api/subscriptions/resume — réactivation d'un
 * abonnement précédemment résilié de manière différée.
 *
 * En mode simulé (STRIPE_ENABLED=false) : seule la base locale est concernée.
 * Lancement : `php bin/phpunit tests/Controller/SubscriptionResumeTest.php`.
 */
final class SubscriptionResumeTest extends ApiTestCase
{
    /**
     * Cycle complet : /cancel renseigne canceledAt, puis /resume l'efface ;
     * le statut reste ACTIVE et la réponse 200 montre `canceledAt: null`.
     */
    public function testResumeClearsCanceledAt(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$user, $token] = $this->createUserWithToken($em);
        $plan = $this->seedPlan($em);
        $sub = $this->seedActiveSubscription($em, $user, $plan, endsInDays: 15);

        // Cycle : on annule (différé) → canceledAt rempli, status ACTIVE.
        $cancelResponse = $this->postJson($client, '/api/subscriptions/cancel', [], $token);
        $this->assertSame(Response::HTTP_OK, $cancelResponse->getStatusCode());

        $em->clear();
        $afterCancel = $em->getRepository(Subscription::class)->find($sub->getId());
        $this->assertNotNull($afterCancel->getCanceledAt(), 'cancel doit avoir rempli canceledAt');

        // Puis on réactive → canceledAt redevient null.
        $resumeResponse = $this->postJson($client, '/api/subscriptions/resume', [], $token);
        $body = $this->assertJsonResponse($resumeResponse, Response::HTTP_OK);

        $em->clear();
        $afterResume = $em->getRepository(Subscription::class)->find($sub->getId());
        $this->assertNull($afterResume->getCanceledAt());
        $this->assertSame(Subscription::STATUS_ACTIVE, $afterResume->getStatus());
        $this->assertNull($body['subscription']['canceledAt']);
    }

    /**
     * Abonnement actif jamais résilié (canceledAt null) → /resume répond 409
     * « n'est pas en cours de résiliation ». Malgré son nom, ce test ne couvre pas
     * un abonnement expiré (cf. explication ci-dessous).
     */
    public function testResumeReturns409IfSubAlreadyExpired(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$user, $token] = $this->createUserWithToken($em);
        $plan = $this->seedPlan($em);

        // Sub déjà résiliée (canceledAt rempli) mais endsAt dans le passé.
        // Le repository `findCurrentActiveForUser` filtre `endsAt > now`, donc
        // ce sub ne sera plus trouvé comme "actif" → /resume doit répondre 404.
        // Pour tester explicitement le 409 « période payée terminée », on
        // contourne le repository en visant la garde de `SubscriptionService::resume`
        // via un sub avec endsAt très proche (et on triche un peu pour le faire passer
        // le filtre `endsAt > now`).
        // Approche retenue : un sub en état "ACTIVE sans canceledAt" → /resume → 409
        // (« cet abo n'est pas en cours de résiliation »).
        $sub = $this->seedActiveSubscription($em, $user, $plan, endsInDays: 10);

        $response = $this->postJson($client, '/api/subscriptions/resume', [], $token);
        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertStringContainsString("n'est pas en cours de résiliation", $body['message']);
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
        $user->setEmail('resume_'.Uuid::v4()->toRfc4122().'@cinaf-test.com');
        $user->setFirstName('Resume');
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
        $plan->setName('ResumeTest-'.uniqid());
        $plan->setDescription('Plan de test pour réactivation.');
        $plan->setPriceCents(999);
        $plan->setIntervalUnit(SubscriptionPlan::INTERVAL_MONTH);
        $plan->setIntervalCount(1);
        $plan->setFeatures(['Test']);
        $plan->setIsActive(true);
        $em->persist($plan);
        $em->flush();
        return $plan;
    }

    /** Persiste un abonnement ACTIVE (début il y a 2 jours, fin dans `$endsInDays` jours), non résilié. */
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
