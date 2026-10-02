<?php

namespace App\Tests\Service;

use App\Entity\Subscription;
use App\Entity\SubscriptionPlan;
use App\Entity\User;
use App\Service\SubscriptionService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Event as StripeEvent;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Tests fonctionnels (DB réelle) de SubscriptionService::syncFromStripeEvent.
 * Pas d'appel HTTP, pas de container Stripe enabled — on injecte directement
 * un \Stripe\Event construit à partir de payloads PHP, comme stripe-php le
 * ferait après vérification de signature.
 *
 * Couvre la création idempotente (`checkout.session.completed`), le
 * rafraîchissement de `endsAt` (`customer.subscription.updated`), l'expiration
 * (`customer.subscription.deleted`) et l'ignorance des autres événements, ainsi
 * que l'activation au retour du client (`fulfillCheckoutSession()`, ajoutée le
 * 2026-09-26) et l'unicité de l'abonnement Stripe en base.
 * Chaque test crée son propre utilisateur et son propre plan (noms uniques) ;
 * rien n'est nettoyé, la base de test étant recréée par run-tests.ps1.
 * Lancement : `php bin/phpunit tests/Service/SubscriptionServiceStripeSyncTest.php`.
 */
final class SubscriptionServiceStripeSyncTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SubscriptionService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(SubscriptionService::class);
    }

    /** checkout.session.completed avec métadonnées valides → abonnement ACTIVE, lié à Stripe, endsAt futur. */
    public function testCheckoutCompletedCreatesActiveSubscription(): void
    {
        [$user, $plan] = $this->seedUserAndPlan();
        $event = $this->buildEvent('checkout.session.completed', [
            'id' => 'cs_test_'.uniqid(),
            'object' => 'checkout.session',
            'mode' => 'subscription',
            'subscription' => 'sub_test_'.uniqid(),
            'customer' => 'cus_test_'.uniqid(),
            'metadata' => [
                'user_id' => $user->getId()->toRfc4122(),
                'plan_id' => $plan->getId()->toRfc4122(),
            ],
        ]);

        $applied = $this->service->syncFromStripeEvent($event);

        $this->assertTrue($applied);
        $sub = $this->em->getRepository(Subscription::class)
            ->findOneBy(['user' => $user]);
        $this->assertNotNull($sub);
        $this->assertSame(Subscription::STATUS_ACTIVE, $sub->getStatus());
        $this->assertNotNull($sub->getStripeSubscriptionId());
        $this->assertNotNull($sub->getEndsAt());
        $this->assertGreaterThan(new \DateTimeImmutable(), $sub->getEndsAt());
    }

    /** Le même événement rejoué deux fois (livraison at-least-once) ne crée qu'un abonnement ; le rejeu renvoie false. */
    public function testCheckoutCompletedIsIdempotent(): void
    {
        [$user, $plan] = $this->seedUserAndPlan();
        $stripeSubId = 'sub_idempotent_'.uniqid();
        $payload = [
            'id' => 'cs_test_'.uniqid(),
            'object' => 'checkout.session',
            'mode' => 'subscription',
            'subscription' => $stripeSubId,
            'customer' => 'cus_test',
            'metadata' => [
                'user_id' => $user->getId()->toRfc4122(),
                'plan_id' => $plan->getId()->toRfc4122(),
            ],
        ];
        $event = $this->buildEvent('checkout.session.completed', $payload);

        $first = $this->service->syncFromStripeEvent($event);
        $second = $this->service->syncFromStripeEvent($event);

        $this->assertTrue($first);
        $this->assertFalse($second, 'Replay must be a no-op');
        $count = $this->em->getRepository(Subscription::class)
            ->count(['stripeSubscriptionId' => $stripeSubId]);
        $this->assertSame(1, $count);
    }

    /** Session sans métadonnées user_id/plan_id → ignorée (false) : le service n'invente aucun rattachement. */
    public function testCheckoutCompletedSkipsWhenMetadataMissing(): void
    {
        $this->seedUserAndPlan();
        $event = $this->buildEvent('checkout.session.completed', [
            'id' => 'cs_test_'.uniqid(),
            'object' => 'checkout.session',
            'mode' => 'subscription',
            'subscription' => 'sub_test_'.uniqid(),
            'customer' => 'cus_test',
            'metadata' => [],
        ]);

        $applied = $this->service->syncFromStripeEvent($event);

        $this->assertFalse($applied);
    }

    /**
     * customer.subscription.updated portant `current_period_end` → endsAt recalé
     * exactement sur ce timestamp, statut ACTIVE conservé. (Le payload n'a pas de
     * `cancel_at_period_end` : c'est la branche de conversion du statut Stripe qui s'exécute.)
     */
    public function testSubscriptionUpdatedRefreshesEndsAt(): void
    {
        [$user, $plan] = $this->seedUserAndPlan();
        $stripeSubId = 'sub_update_'.uniqid();

        // Crée d'abord une Subscription via checkout.session.completed.
        $this->service->syncFromStripeEvent($this->buildEvent('checkout.session.completed', [
            'id' => 'cs_'.uniqid(),
            'object' => 'checkout.session',
            'mode' => 'subscription',
            'subscription' => $stripeSubId,
            'customer' => 'cus_test',
            'metadata' => [
                'user_id' => $user->getId()->toRfc4122(),
                'plan_id' => $plan->getId()->toRfc4122(),
            ],
        ]));

        $futureTs = (new \DateTimeImmutable('+45 days'))->getTimestamp();
        $updateEvent = $this->buildEvent('customer.subscription.updated', [
            'id' => $stripeSubId,
            'object' => 'subscription',
            'status' => 'active',
            'current_period_end' => $futureTs,
        ]);

        $applied = $this->service->syncFromStripeEvent($updateEvent);

        $this->assertTrue($applied);
        $sub = $this->em->getRepository(Subscription::class)
            ->findOneBy(['stripeSubscriptionId' => $stripeSubId]);
        $this->assertNotNull($sub);
        $this->assertSame(Subscription::STATUS_ACTIVE, $sub->getStatus());
        $this->assertSame($futureTs, $sub->getEndsAt()?->getTimestamp());
    }

    /**
     * customer.subscription.deleted → abonnement local EXPIRED avec canceledAt renseigné.
     * (Le nom de la méthode date d'avant la refonte : l'état attendu n'est plus CANCELED.)
     */
    public function testSubscriptionDeletedCancelsLocalSubscription(): void
    {
        [$user, $plan] = $this->seedUserAndPlan();
        $stripeSubId = 'sub_delete_'.uniqid();

        $this->service->syncFromStripeEvent($this->buildEvent('checkout.session.completed', [
            'id' => 'cs_'.uniqid(),
            'object' => 'checkout.session',
            'mode' => 'subscription',
            'subscription' => $stripeSubId,
            'customer' => 'cus_test',
            'metadata' => [
                'user_id' => $user->getId()->toRfc4122(),
                'plan_id' => $plan->getId()->toRfc4122(),
            ],
        ]));

        $deleteEvent = $this->buildEvent('customer.subscription.deleted', [
            'id' => $stripeSubId,
            'object' => 'subscription',
            'status' => 'canceled',
        ]);

        $applied = $this->service->syncFromStripeEvent($deleteEvent);

        $this->assertTrue($applied);
        $sub = $this->em->getRepository(Subscription::class)
            ->findOneBy(['stripeSubscriptionId' => $stripeSubId]);
        // Depuis la refonte « résiliation différée » (2026-05-20), un event
        // customer.subscription.deleted (envoyé par Stripe en fin de période
        // payée) marque l'abonnement comme EXPIRED et non plus CANCELED.
        // CANCELED reste réservé à l'historique pré-refonte.
        $this->assertSame(Subscription::STATUS_EXPIRED, $sub->getStatus());
        $this->assertNotNull($sub->getCanceledAt());
    }

    /** Un type d'événement non géré (payment_intent.created) est ignoré : false, sans effet. */
    public function testUnknownEventTypeIsIgnored(): void
    {
        $event = $this->buildEvent('payment_intent.created', [
            'id' => 'pi_test',
            'object' => 'payment_intent',
        ]);

        $this->assertFalse($this->service->syncFromStripeEvent($event));
    }

    /**
     * Retour du client après paiement (session `complete` + `paid`) : l'abonnement
     * est actif tout de suite, sans attendre le webhook — le cas signalé le
     * 2026-09-26, où le webhook n'arrivait jamais en local.
     */
    public function testReturnFulfillmentActivatesPaidSession(): void
    {
        [$user, $plan] = $this->seedUserAndPlan();
        $session = $this->buildSession($user, $plan, 'sub_return_'.uniqid());

        $this->assertTrue($this->service->fulfillCheckoutSession($session, $user));

        $sub = $this->em->getRepository(Subscription::class)->findOneBy(['user' => $user]);
        $this->assertNotNull($sub);
        $this->assertSame(Subscription::STATUS_ACTIVE, $sub->getStatus());
        $this->assertTrue($this->service->hasActiveSubscription($user));
    }

    /**
     * Retour du client, puis webhook, puis rechargement de la page de succès :
     * un seul abonnement est créé, les signaux suivants renvoient false.
     */
    public function testReturnFulfillmentAndWebhookCreateOnlyOneSubscription(): void
    {
        [$user, $plan] = $this->seedUserAndPlan();
        $stripeSubId = 'sub_both_'.uniqid();
        $session = $this->buildSession($user, $plan, $stripeSubId);

        $this->assertTrue($this->service->fulfillCheckoutSession($session, $user));
        $this->assertFalse($this->service->syncFromStripeEvent(
            $this->buildEvent('checkout.session.completed', $session->toArray()),
        ), 'Le webhook arrivé après le retour est un no-op');
        $this->assertFalse($this->service->fulfillCheckoutSession($session, $user), 'Rechargement de la page');

        $this->assertSame(1, $this->em->getRepository(Subscription::class)
            ->count(['stripeSubscriptionId' => $stripeSubId]));
    }

    /** La session payée par un autre utilisateur ne peut pas être activée : rien n'est créé. */
    public function testReturnFulfillmentRefusesSessionOfAnotherUser(): void
    {
        [$owner, $plan] = $this->seedUserAndPlan();
        [$intruder] = $this->seedUserAndPlan();
        $session = $this->buildSession($owner, $plan, 'sub_other_'.uniqid());

        $this->assertFalse($this->service->fulfillCheckoutSession($session, $intruder));

        $repo = $this->em->getRepository(Subscription::class);
        $this->assertSame(0, $repo->count(['user' => $owner]));
        $this->assertSame(0, $repo->count(['user' => $intruder]));
    }

    /** Paiement non abouti (session encore ouverte, ou terminée mais impayée) : aucune activation. */
    public function testReturnFulfillmentIgnoresUnpaidSession(): void
    {
        [$user, $plan] = $this->seedUserAndPlan();
        $open = $this->buildSession($user, $plan, 'sub_open_'.uniqid(), status: 'open', paymentStatus: 'unpaid');
        $unpaid = $this->buildSession($user, $plan, 'sub_unpaid_'.uniqid(), paymentStatus: 'unpaid');

        $this->assertFalse($this->service->fulfillCheckoutSession($open, $user));
        $this->assertFalse($this->service->fulfillCheckoutSession($unpaid, $user));
        $this->assertFalse($this->service->hasActiveSubscription($user));
    }

    /**
     * Garde-fou en base (migration Version20260926100000) : deux lignes ne
     * peuvent pas porter le même abonnement Stripe. C'est ce qui départage le
     * retour du client et le webhook s'ils arrivent au même instant.
     */
    public function testStripeSubscriptionIdIsUniqueInDatabase(): void
    {
        [$user, $plan] = $this->seedUserAndPlan();
        $stripeSubId = 'sub_unique_'.uniqid();
        foreach ([1, 2] as $ignored) {
            $sub = new Subscription();
            $sub->setUser($user);
            $sub->setPlan($plan);
            $sub->setStripeSubscriptionId($stripeSubId);
            $this->em->persist($sub);
        }

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    /**
     * Crée en base un utilisateur (email unique) et un plan mensuel actif doté
     * d'un stripePriceId factice.
     *
     * @return array{0:User,1:SubscriptionPlan}
     */
    private function seedUserAndPlan(): array
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('stripe_sync_'.Uuid::v4()->toRfc4122().'@cinaf-test.com');
        $user->setFirstName('Stripe');
        $user->setLastName('SyncTest');
        $user->setPassword($hasher->hashPassword($user, 'Password123!'));
        $user->setRoles(['ROLE_USER']);
        $user->setIsVerified(true);

        $plan = new SubscriptionPlan();
        $plan->setName('Mensuel-StripeSyncTest-'.uniqid());
        $plan->setDescription('Plan de test pour sync Stripe.');
        $plan->setPriceCents(999);
        $plan->setIntervalUnit(SubscriptionPlan::INTERVAL_MONTH);
        $plan->setIntervalCount(1);
        $plan->setFeatures(['Test']);
        $plan->setIsActive(true);
        $plan->setStripePriceId('price_test_'.uniqid());

        $this->em->persist($user);
        $this->em->persist($plan);
        $this->em->flush();

        return [$user, $plan];
    }

    /**
     * Construit la session Checkout que StripeService::retrieveCheckoutSession()
     * renverrait au retour du client : mode abonnement, métadonnées posées par
     * createCheckoutSession(), statut et état du paiement paramétrables.
     */
    private function buildSession(
        User $user,
        SubscriptionPlan $plan,
        string $stripeSubId,
        string $status = 'complete',
        string $paymentStatus = 'paid',
    ): CheckoutSession {
        return CheckoutSession::constructFrom([
            'id' => 'cs_test_'.uniqid(),
            'object' => 'checkout.session',
            'mode' => 'subscription',
            'status' => $status,
            'payment_status' => $paymentStatus,
            'subscription' => $stripeSubId,
            'customer' => 'cus_test_'.uniqid(),
            'metadata' => [
                'user_id' => $user->getId()->toRfc4122(),
                'plan_id' => $plan->getId()->toRfc4122(),
            ],
        ]);
    }

    /**
     * Construit un \Stripe\Event du type donné enveloppant `$object` : stripe-php
     * convertit `data.object` en Checkout\Session ou Subscription selon son champ `object`.
     */
    private function buildEvent(string $type, array $object): StripeEvent
    {
        return StripeEvent::constructFrom([
            'id' => 'evt_'.uniqid(),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $object],
        ]);
    }
}
