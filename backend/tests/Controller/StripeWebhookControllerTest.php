<?php

namespace App\Tests\Controller;

use App\Tests\Support\ApiTestCase;

/**
 * Tests fonctionnels du webhook Stripe.
 *
 * En environnement test, STRIPE_ENABLED=false (hérité de .env). Le contrôleur
 * doit refuser proprement les hits (503) sans toucher la DB ni lever d'exception
 * — preuve que la route est mappée, que le firewall laisse passer la requête
 * (PUBLIC_ACCESS dans security.yaml) et que le garde-fou fonctionne.
 *
 * La validation de signature et la sync DB sont couvertes par
 * StripeServiceTest et SubscriptionServiceStripeSyncTest.
 */
final class StripeWebhookControllerTest extends ApiTestCase
{
    public function testReturns503WhenStripeDisabled(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/stripe/webhook', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't=0,v1=anything',
        ], '{}');

        $response = $client->getResponse();
        $this->assertSame(503, $response->getStatusCode());
        $this->assertJson($response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->assertStringContainsString('désactivé', $body['message']);
    }

    public function testRouteAcceptsPostMethodOnly(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/stripe/webhook');
        $this->assertSame(405, $client->getResponse()->getStatusCode());
    }
}
