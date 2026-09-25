<?php

namespace App\Tests\OpenApi;

use App\OpenApi\RouteDocumentation;
use App\OpenApi\RouteOpenApiFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Garantit que la documentation OpenAPI (Swagger UI sur /api) couvre TOUTES
 * les routes /api de l'application, que chacune y est décrite, et que le
 * cadenas JWT affiché correspond aux règles réelles du pare-feu.
 *
 * Le test lit la spec réellement servie par GET /api/docs.jsonopenapi (celle
 * que consomme Swagger UI) et la confronte au routeur Symfony : une route
 * ajoutée sans description fait échouer la suite.
 *
 * Run: php bin/phpunit tests/OpenApi/OpenApiRouteCoverageTest.php
 */
class OpenApiRouteCoverageTest extends WebTestCase
{
    private const HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Spec et routes lues une seule fois pour toute la classe : générer la
     * spec prend plusieurs secondes (kernel de test en debug), et ces données
     * ne dépendent que de la configuration, jamais d'un test.
     *
     * @var array<string, array<string, mixed>>|null « MÉTHODE /chemin » => opération OpenAPI
     */
    private static ?array $operations = null;

    /** @var array<string, string>|null « MÉTHODE /chemin » => nom de la route Symfony */
    private static ?array $routes = null;

    protected function setUp(): void
    {
        if (null !== self::$operations) {
            return;
        }

        // Client créé AVANT tout accès au conteneur (un seul boot du kernel).
        $client = static::createClient();
        $client->request('GET', '/api/docs.jsonopenapi', server: ['HTTP_ACCEPT' => 'application/vnd.openapi+json']);
        self::assertResponseIsSuccessful();
        $spec = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        $operations = [];
        foreach ($spec['paths'] as $path => $pathItem) {
            foreach ($pathItem as $method => $operation) {
                if (\in_array(strtoupper($method), self::HTTP_METHODS, true)) {
                    $operations[strtoupper($method).' '.$path] = $operation;
                }
            }
        }

        $routes = [];
        /** @var RouterInterface $router */
        $router = static::getContainer()->get('router');
        foreach ($router->getRouteCollection() as $name => $route) {
            $controller = $route->getDefault('_controller');
            // Routes internes d'API Platform (docs, contextes JSON-LD, erreurs) exclues.
            if (!str_starts_with($route->getPath(), '/api/')
                || (\is_string($controller) && str_starts_with($controller, 'api_platform.'))) {
                continue;
            }
            foreach (array_intersect($route->getMethods() ?: ['GET'], self::HTTP_METHODS) as $method) {
                $routes[$method.' '.$route->getPath()] = $name;
            }
        }

        self::$operations = $operations;
        self::$routes = $routes;
    }

    public function testEveryApiRouteAppearsInTheDocumentation(): void
    {
        // Garde-fou : le routeur a bien été lu (plus de 110 opérations à ce jour).
        self::assertGreaterThan(110, \count(self::$routes));

        $missing = array_keys(array_diff_key(self::$routes, self::$operations));
        self::assertSame([], $missing, "Routes absentes de la documentation OpenAPI :\n".implode("\n", $missing));
    }

    public function testEveryRouteIsDescribed(): void
    {
        $undescribed = [];
        foreach (array_intersect_key(self::$operations, self::$routes) as $key => $operation) {
            $summary = $operation['summary'] ?? '';
            if ('' === $summary || RouteOpenApiFactory::UNDOCUMENTED === $summary) {
                $undescribed[] = $key;
            }
        }
        self::assertSame([], $undescribed, "Routes à décrire dans App\\OpenApi\\RouteDocumentation :\n".implode("\n", $undescribed));
    }

    public function testRouteDocumentationHasNoEntryForADeletedRoute(): void
    {
        $stale = array_keys(array_diff_key(RouteDocumentation::all(), self::$routes));
        self::assertSame([], $stale, "Entrées de RouteDocumentation sans route correspondante :\n".implode("\n", $stale));
    }

    public function testOperationIdsAreUnique(): void
    {
        $counts = array_count_values(array_column(self::$operations, 'operationId'));
        $duplicates = array_keys(array_filter($counts, fn (int $count) => $count > 1));
        self::assertSame([], $duplicates, 'operationId en double : '.implode(', ', $duplicates));
    }

    /**
     * Le cadenas « JWT requis » doit refléter le pare-feu : #[IsGranted] sur
     * la classe ou la méthode, et règles access_control de security.yaml.
     */
    public function testJwtRequirementMatchesTheFirewall(): void
    {
        foreach (self::$operations as $key => $operation) {
            [, $path] = explode(' ', $key, 2);
            if (str_starts_with($path, '/api/admin/') || str_starts_with($path, '/api/studio/')) {
                self::assertTrue($this->requiresJwt($operation), "$key devrait exiger un JWT.");
            }
            if (str_starts_with($path, '/api/admin/')) {
                self::assertStringContainsString('ROLE_ADMIN', $operation['description'] ?? '', $key);
            }
        }

        // Public (access_control PUBLIC_ACCESS ou aucune barrière)…
        foreach (['GET /api/catalogue/discover', 'GET /api/catalogue/discover/{slug}', 'GET /api/studios',
            'GET /api/studios/{slug}', 'GET /api/subscription-plans', 'POST /api/stripe/webhook'] as $key) {
            self::assertFalse($this->requiresJwt(self::$operations[$key]), "$key devrait être public.");
        }
        // … et protégées par un #[IsGranted] de classe ou de méthode, même
        // sous un chemin public (/api/studios, /api/catalogue).
        foreach (['GET /api/catalogue/discover/{slug}/can-play', 'POST /api/studios/{slug}/subscribe',
            'GET /api/subscriptions/current'] as $key) {
            self::assertTrue($this->requiresJwt(self::$operations[$key]), "$key devrait exiger un JWT.");
        }
    }

    /**
     * @param array<string, mixed> $operation
     */
    private function requiresJwt(array $operation): bool
    {
        return \in_array(['bearerAuth' => []], $operation['security'] ?? [], true);
    }
}
