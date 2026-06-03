<?php

namespace App\Tests\Service;

use App\Service\StripeService;
use PHPUnit\Framework\TestCase;
use Stripe\Exception\SignatureVerificationException;

/**
 * Tests unitaires purs de StripeService.
 * Pas de container Symfony, pas de DB : on instancie directement le service
 * avec les bons paramètres et on valide les garde-fous + la vérification
 * de signature webhook (l'algorithme est reproductible en local).
 */
final class StripeServiceTest extends TestCase
{
    private const WEBHOOK_SECRET = 'whsec_test_dummy';

    public function testIsEnabledReflectsConstructorFlag(): void
    {
        $disabled = new StripeService('sk_test', self::WEBHOOK_SECRET, 'http://success', 'http://cancel', false);
        $enabled = new StripeService('sk_test', self::WEBHOOK_SECRET, 'http://success', 'http://cancel', true);

        $this->assertFalse($disabled->isEnabled());
        $this->assertTrue($enabled->isEnabled());
    }

    public function testConstructWebhookEventThrowsWhenDisabled(): void
    {
        $svc = new StripeService('sk_test', self::WEBHOOK_SECRET, 'http://success', 'http://cancel', false);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/STRIPE_ENABLED=false/');

        $svc->constructWebhookEvent('{}', 't=0,v1=fake');
    }

    public function testConstructWebhookEventRejectsInvalidSignature(): void
    {
        $svc = new StripeService('sk_test', self::WEBHOOK_SECRET, 'http://success', 'http://cancel', true);

        $this->expectException(SignatureVerificationException::class);

        // Payload valide JSON Stripe-shape, signature volontairement invalide.
        $svc->constructWebhookEvent('{"id":"evt_1","type":"foo"}', 't=1234,v1=invalidsignature');
    }

    public function testConstructWebhookEventAcceptsValidSignature(): void
    {
        $svc = new StripeService('sk_test', self::WEBHOOK_SECRET, 'http://success', 'http://cancel', true);

        $payload = json_encode([
            'id' => 'evt_test_1',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_test_123', 'object' => 'checkout.session']],
        ]);
        $sigHeader = self::signPayload($payload, self::WEBHOOK_SECRET);

        $event = $svc->constructWebhookEvent($payload, $sigHeader);

        $this->assertSame('evt_test_1', $event->id);
        $this->assertSame('checkout.session.completed', $event->type);
    }

    /**
     * Reproduit l'algorithme de signature Stripe pour les tests.
     * Cf. https://stripe.com/docs/webhooks/signatures#verify-manually
     */
    public static function signPayload(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $signedPayload = $timestamp . '.' . $payload;
        $signature = hash_hmac('sha256', $signedPayload, $secret);
        return sprintf('t=%d,v1=%s', $timestamp, $signature);
    }
}
