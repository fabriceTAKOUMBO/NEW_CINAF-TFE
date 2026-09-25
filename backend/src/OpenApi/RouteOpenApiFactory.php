<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\AccessMapInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * ============================================================
 * CINAF v2 — Documentation OpenAPI de toutes les routes /api
 * ============================================================
 * API Platform ne documente que ses propres ressources. Or l'API CINAF est
 * faite de contrôleurs Symfony classiques (Auth, catalogue, Admin, Studio,
 * abonnements…), invisibles dans Swagger UI tant qu'on ne les décrit pas.
 * `OpenApiFactory` en décrit une partie à la main ; les routes ajoutées
 * ensuite (Admin, Studio, Découvrir…) manquaient.
 *
 * Ce décorateur s'exécute APRÈS `OpenApiFactory` et parcourt le routeur
 * Symfony : chaque route /api encore absente de la documentation y est
 * ajoutée. Aucune route ne peut donc manquer, y compris les futures.
 *  - Le texte (groupe, résumé, paramètres de requête, corps) vient de
 *    `RouteDocumentation`.
 *  - Le reste est déduit de la route elle-même : paramètres de chemin et
 *    leurs contraintes, jeton JWT requis et rôle exigé, lus dans les mêmes
 *    sources que le pare-feu (#[IsGranted] et access_control).
 *
 * Une route oubliée dans `RouteDocumentation` apparaît quand même, avec le
 * résumé `UNDOCUMENTED` que le test `OpenApiRouteCoverageTest` détecte.
 */
final class RouteOpenApiFactory implements OpenApiFactoryInterface
{
    /** Résumé d'une route sans entrée dans RouteDocumentation (détecté par le test). */
    public const UNDOCUMENTED = 'Description à compléter dans App\OpenApi\RouteDocumentation';

    /** Méthodes HTTP qu'un PathItem OpenAPI sait porter. */
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /** Description et format des paramètres de chemin, par nom. */
    private const PATH_PARAMETERS = [
        'id' => ['Identifiant (UUID)', 'uuid'],
        'serieId' => ['Identifiant (UUID) de la série', 'uuid'],
        'seasonId' => ['Identifiant (UUID) de la saison', 'uuid'],
        'episodeId' => ["Identifiant (UUID) de l'épisode", 'uuid'],
        'slug' => ["Slug (identifiant lisible dans l'URL)", null],
        'sessionId' => ['Identifiant de session Stripe Checkout (cs_…)', null],
    ];

    /** Libellés des codes de succès. */
    private const SUCCESS = [
        200 => 'Succès',
        201 => 'Ressource créée',
        204 => 'Succès, réponse sans contenu',
    ];

    public function __construct(
        private readonly OpenApiFactoryInterface $decorated,
        private readonly RouterInterface $router,
        private readonly AccessMapInterface $accessMap,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $paths = $openApi->getPaths();
        $documentation = RouteDocumentation::all();

        foreach ($this->router->getRouteCollection() as $name => $route) {
            if (!$this->isApplicationApiRoute($route)) {
                continue;
            }
            $path = $route->getPath();
            $methods = array_intersect($route->getMethods() ?: ['GET'], self::METHODS);

            foreach ($methods as $method) {
                $pathItem = $paths->getPath($path) ?? new PathItem();
                $verb = ucfirst(strtolower($method));
                // Opération déjà décrite à la main dans OpenApiFactory : on n'y touche pas.
                if (null !== $pathItem->{'get'.$verb}()) {
                    continue;
                }
                $operation = $this->buildOperation(
                    $name,
                    $route,
                    $method,
                    \count($methods) > 1,
                    $documentation["$method $path"] ?? null,
                );
                $paths->addPath($path, $pathItem->{'with'.$verb}($operation));
            }
        }

        return $openApi;
    }

    /**
     * Route applicative sous /api. Les routes internes d'API Platform
     * (documentation, contextes JSON-LD, erreurs…) sont exclues : elles ne
     * font pas partie de l'API métier.
     */
    private function isApplicationApiRoute(Route $route): bool
    {
        $controller = $route->getDefault('_controller');

        return str_starts_with($route->getPath(), '/api/')
            && !(\is_string($controller) && str_starts_with($controller, 'api_platform.'));
    }

    /**
     * @param array<string, mixed>|null $doc Entrée de RouteDocumentation, null si absente
     */
    private function buildOperation(string $routeName, Route $route, string $method, bool $multiMethod, ?array $doc): Operation
    {
        $access = $this->requiredAccess($route, $method);
        $pathParameters = $this->pathParameters($route);
        $parameters = [
            ...$pathParameters,
            ...$this->parameters($doc['query'] ?? [], 'query'),
            ...$this->headerParameters($doc['headers'] ?? []),
        ];
        $requestBody = $this->requestBody($doc);

        return new Operation(
            operationId: $this->operationId($routeName, $method, $multiMethod),
            tags: [$doc['tag'] ?? 'Routes à documenter'],
            responses: $this->responses($doc, $access, $pathParameters !== [], $requestBody !== null || isset($doc['query'])),
            summary: $doc['summary'] ?? self::UNDOCUMENTED,
            description: null === $access
                ? 'Accès public.'
                : "Accès : $access. Jeton JWT requis (bouton « Authorize »).",
            parameters: $parameters,
            requestBody: $requestBody,
            security: null === $access ? [] : [['bearerAuth' => []]],
        );
    }

    /**
     * Accès exigé par la route, ou null si elle est publique. Deux barrières
     * existent dans CINAF : l'attribut #[IsGranted] (classe et méthode du
     * contrôleur) et la règle access_control qui correspond au chemin. On
     * lit exactement ces sources, pour que la documentation dise ce que le
     * pare-feu applique réellement.
     */
    private function requiredAccess(Route $route, string $method): ?string
    {
        $roles = $this->isGrantedAttributes($route->getDefault('_controller'));

        // Chemin concret pour interroger les règles access_control.
        $url = preg_replace('/\{\w+\}/', 'x', $route->getPath());
        [$accessControl] = $this->accessMap->getPatterns(Request::create($url, $method));
        foreach ($accessControl ?? [] as $attribute) {
            if (\is_string($attribute) && 'PUBLIC_ACCESS' !== $attribute) {
                $roles[] = $attribute;
            }
        }
        $roles = array_values(array_unique($roles));
        if ([] === $roles) {
            return null;
        }
        // Un rôle précis rend « IS_AUTHENTICATED_FULLY » redondant.
        if (\count($roles) > 1) {
            $roles = array_values(array_diff($roles, ['IS_AUTHENTICATED_FULLY']));
        }

        return implode(' + ', array_map(
            fn (string $role) => 'IS_AUTHENTICATED_FULLY' === $role ? 'utilisateur connecté' : $role,
            $roles,
        ));
    }

    /**
     * Rôles des attributs #[IsGranted] de la classe puis de la méthode du
     * contrôleur (« App\…\Controller::methode »).
     *
     * @return list<string>
     */
    private function isGrantedAttributes(mixed $controller): array
    {
        if (!\is_string($controller) || !str_contains($controller, '::')) {
            return [];
        }
        [$class, $method] = explode('::', $controller, 2);
        if (!method_exists($class, $method)) {
            return [];
        }
        $reflectionMethod = new \ReflectionMethod($class, $method);
        $attributes = [
            ...$reflectionMethod->getDeclaringClass()->getAttributes(IsGranted::class),
            ...$reflectionMethod->getAttributes(IsGranted::class),
        ];
        $roles = [];
        foreach ($attributes as $attribute) {
            $role = $attribute->newInstance()->attribute;
            if (\is_string($role)) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    /**
     * Paramètres de chemin extraits du motif de la route ({id}, {slug}…),
     * avec la contrainte `requirements` de la route quand elle existe.
     *
     * @return list<Parameter>
     */
    private function pathParameters(Route $route): array
    {
        preg_match_all('/\{(\w+)\}/', $route->getPath(), $matches);

        return array_map(function (string $name) use ($route): Parameter {
            [$description, $format] = self::PATH_PARAMETERS[$name] ?? [ucfirst($name), null];
            $schema = ['type' => 'string'];
            if (null !== $format) {
                $schema['format'] = $format;
            }
            if (null !== $requirement = $route->getRequirement($name)) {
                $schema['pattern'] = '^'.$requirement.'$';
            }

            return new Parameter($name, 'path', $description, required: true, schema: $schema);
        }, $matches[1]);
    }

    /**
     * @param array<string, array{0: string, 1: string}> $fields nom => [type, description]
     *
     * @return list<Parameter>
     */
    private function parameters(array $fields, string $in): array
    {
        $parameters = [];
        foreach ($fields as $name => [$type, $description]) {
            $parameters[] = new Parameter($name, $in, $description, schema: $this->schemaType($type));
        }

        return $parameters;
    }

    /**
     * @param array<string, string> $headers nom => description
     *
     * @return list<Parameter>
     */
    private function headerParameters(array $headers): array
    {
        $parameters = [];
        foreach ($headers as $name => $description) {
            $parameters[] = new Parameter($name, 'header', $description, required: true, schema: ['type' => 'string']);
        }

        return $parameters;
    }

    /**
     * Corps de requête : JSON (`body`) ou formulaire multipart (`multipart`,
     * pour l'upload de fichiers). Une chaîne dans `body` décrit un corps
     * libre, dont la structure est imposée par un tiers (événement Stripe).
     *
     * @param array<string, mixed>|null $doc
     */
    private function requestBody(?array $doc): ?RequestBody
    {
        if (isset($doc['multipart'])) {
            $mediaType = 'multipart/form-data';
            $schema = $this->objectSchema($doc['multipart'], $doc['required'] ?? []);
        } elseif (isset($doc['body']) && \is_string($doc['body'])) {
            $mediaType = 'application/json';
            $schema = ['type' => 'object', 'description' => $doc['body']];
        } elseif (isset($doc['body'])) {
            $mediaType = 'application/json';
            $schema = $this->objectSchema($doc['body'], $doc['required'] ?? []);
        } else {
            return null;
        }

        return new RequestBody(
            content: new \ArrayObject([$mediaType => new MediaType(new \ArrayObject($schema))]),
            required: [] !== ($doc['required'] ?? []) || \is_string($doc['body'] ?? null),
        );
    }

    /**
     * @param array<string, array{0: string, 1: string}> $fields   nom => [type, description]
     * @param list<string>                               $required
     *
     * @return array<string, mixed>
     */
    private function objectSchema(array $fields, array $required): array
    {
        $properties = [];
        foreach ($fields as $name => [$type, $description]) {
            $properties[$name] = $this->schemaType($type) + ['description' => $description];
        }
        $schema = ['type' => 'object', 'properties' => $properties];
        if ([] !== $required) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * Traduit un type abrégé de RouteDocumentation en schéma JSON. La spec
     * générée est en OpenAPI 3.1 : un champ effaçable s'écrit donc avec un
     * type ["string", "null"] (le mot-clé `nullable` n'existe plus).
     *
     * @return array<string, mixed>
     */
    private function schemaType(string $type): array
    {
        $nullable = str_ends_with($type, '|null');
        if ($nullable) {
            $type = substr($type, 0, -\strlen('|null'));
        }
        $schema = match (true) {
            str_starts_with($type, 'enum:') => ['type' => 'string', 'enum' => explode(',', substr($type, \strlen('enum:')))],
            'uuid' === $type => ['type' => 'string', 'format' => 'uuid'],
            'date-time' === $type => ['type' => 'string', 'format' => 'date-time'],
            'binary' === $type => ['type' => 'string', 'format' => 'binary'],
            'string[]' === $type => ['type' => 'array', 'items' => ['type' => 'string']],
            default => ['type' => $type],
        };
        if ($nullable) {
            $schema['type'] = [$schema['type'], 'null'];
        }

        return $schema;
    }

    /**
     * Réponses : le code de succès, puis les erreurs que la route peut
     * renvoyer d'après sa nature (entrée à valider, JWT, rôle, ressource
     * désignée dans le chemin), complétées par celles de RouteDocumentation.
     *
     * @param array<string, mixed>|null $doc
     *
     * @return array<int, \ArrayObject<string, string>>
     */
    private function responses(?array $doc, ?string $access, bool $hasPathParameters, bool $hasInput): array
    {
        $status = $doc['status'] ?? 200;
        $responses = [$status => self::SUCCESS[$status] ?? 'Succès'];
        if ($hasInput) {
            $responses[400] = 'Requête invalide (paramètre ou corps JSON)';
        }
        if (null !== $access) {
            $responses[401] = 'Jeton JWT absent, invalide ou expiré';
            if (str_contains($access, 'ROLE_')) {
                $responses[403] = 'Rôle insuffisant';
            }
        }
        if ($hasPathParameters) {
            $responses[404] = 'Ressource introuvable';
        }
        foreach ($doc['responses'] ?? [] as $code => $description) {
            $responses[$code] = $description;
        }
        ksort($responses);

        return array_map(fn (string $description) => new \ArrayObject(['description' => $description]), $responses);
    }

    /**
     * Identifiant d'opération dérivé du nom de route (« admin_films_list » →
     * « adminFilmsList »), suffixé par la méthode quand une même route en
     * accepte plusieurs (approve/reject : POST et PATCH).
     */
    private function operationId(string $routeName, string $method, bool $multiMethod): string
    {
        $id = lcfirst(str_replace(' ', '', ucwords(str_replace(['_', '-', '.'], ' ', $routeName))));

        return $multiMethod ? $id.ucfirst(strtolower($method)) : $id;
    }
}
