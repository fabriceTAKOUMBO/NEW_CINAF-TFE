<?php
namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Components;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\SecurityScheme;
use ApiPlatform\OpenApi\OpenApi;

class OpenApiFactory implements OpenApiFactoryInterface
{
    public function __construct(private OpenApiFactoryInterface $decorated) {}

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);

        // ── Security scheme JWT Bearer ────────────────────────────────────
        $components = $openApi->getComponents() ?? new Components();
        $securitySchemes = $components->getSecuritySchemes() ?? new \ArrayObject();
        $securitySchemes['bearerAuth'] = new SecurityScheme(
            type: 'http',
            scheme: 'bearer',
            bearerFormat: 'JWT',
            description: 'Entrez votre access_token JWT obtenu via POST /api/auth/login',
        );
        $openApi = $openApi->withComponents($components->withSecuritySchemes($securitySchemes));

        // ── Helpers ───────────────────────────────────────────────────────
        $public  = [];
        $secured = [['bearerAuth' => []]];

        $jsonBody = fn(array $schema, string $description = '') => new RequestBody(
            description: $description,
            content: new \ArrayObject(['application/json' => new MediaType(new \ArrayObject($schema))]),
            required: true,
        );

        $responses = fn(array $codes): array => array_map(
            fn($desc) => new \ArrayObject(['description' => $desc]),
            $codes
        );

        // ── /api/auth/register ────────────────────────────────────────────
        $openApi->getPaths()->addPath('/api/auth/register', new PathItem(
            post: new Operation(
                operationId: 'authRegister',
                tags: ['Auth'],
                responses: $responses([201 => 'Inscription réussie', 400 => 'Données invalides', 409 => 'Email déjà utilisé']),
                summary: 'Créer un compte utilisateur',
                requestBody: $jsonBody([
                    'type' => 'object',
                    'required' => ['email', 'password', 'firstName', 'lastName'],
                    'properties' => [
                        'email'       => ['type' => 'string', 'format' => 'email'],
                        'password'    => ['type' => 'string', 'minLength' => 8],
                        'firstName'   => ['type' => 'string'],
                        'lastName'    => ['type' => 'string'],
                        'consentRgpd' => ['type' => 'boolean'],
                    ],
                ]),
                security: $public,
            ),
        ));

        // ── /api/auth/login ───────────────────────────────────────────────
        $openApi->getPaths()->addPath('/api/auth/login', new PathItem(
            post: new Operation(
                operationId: 'authLogin',
                tags: ['Auth'],
                responses: $responses([200 => 'Connexion réussie — retourne access_token, refresh_token, user', 401 => 'Identifiants incorrects']),
                summary: 'Se connecter (obtenir un JWT)',
                requestBody: $jsonBody([
                    'type' => 'object',
                    'required' => ['email', 'password'],
                    'properties' => [
                        'email'    => ['type' => 'string', 'format' => 'email'],
                        'password' => ['type' => 'string'],
                    ],
                ]),
                security: $public,
            ),
        ));

        // ── /api/auth/logout ──────────────────────────────────────────────
        $openApi->getPaths()->addPath('/api/auth/logout', new PathItem(
            post: new Operation(
                operationId: 'authLogout',
                tags: ['Auth'],
                responses: $responses([204 => 'Déconnexion réussie', 401 => 'Non authentifié']),
                summary: 'Se déconnecter (invalider le refresh_token)',
                requestBody: $jsonBody([
                    'type' => 'object',
                    'properties' => ['refresh_token' => ['type' => 'string']],
                ]),
                security: $secured,
            ),
        ));

        // ── /api/auth/refresh ─────────────────────────────────────────────
        $openApi->getPaths()->addPath('/api/auth/refresh', new PathItem(
            post: new Operation(
                operationId: 'authRefresh',
                tags: ['Auth'],
                responses: $responses([200 => 'Nouveaux tokens retournés', 400 => 'Token manquant', 401 => 'Token invalide ou expiré']),
                summary: 'Rafraîchir le JWT via le refresh_token',
                requestBody: $jsonBody([
                    'type' => 'object',
                    'required' => ['refresh_token'],
                    'properties' => ['refresh_token' => ['type' => 'string']],
                ]),
                security: $public,
            ),
        ));

        // ── /api/auth/forgot-password ─────────────────────────────────────
        $openApi->getPaths()->addPath('/api/auth/forgot-password', new PathItem(
            post: new Operation(
                operationId: 'authForgotPassword',
                tags: ['Auth'],
                responses: $responses([200 => 'Réponse générique (anti-énumération)']),
                summary: 'Demander un lien de réinitialisation du mot de passe',
                requestBody: $jsonBody([
                    'type' => 'object',
                    'required' => ['email'],
                    'properties' => ['email' => ['type' => 'string', 'format' => 'email']],
                ]),
                security: $public,
            ),
        ));

        // ── /api/auth/reset-password ──────────────────────────────────────
        $openApi->getPaths()->addPath('/api/auth/reset-password', new PathItem(
            post: new Operation(
                operationId: 'authResetPassword',
                tags: ['Auth'],
                responses: $responses([200 => 'Mot de passe réinitialisé', 400 => 'Token invalide ou expiré']),
                summary: 'Réinitialiser le mot de passe via le token reçu par email',
                requestBody: $jsonBody([
                    'type' => 'object',
                    'required' => ['token', 'password'],
                    'properties' => [
                        'token'    => ['type' => 'string'],
                        'password' => ['type' => 'string', 'minLength' => 8],
                    ],
                ]),
                security: $public,
            ),
        ));

        // ── /api/auth/me ──────────────────────────────────────────────────
        $openApi->getPaths()->addPath('/api/auth/me', new PathItem(
            get: new Operation(
                operationId: 'authMe',
                tags: ['Auth'],
                responses: $responses([200 => 'Profil de l\'utilisateur connecté', 401 => 'Non authentifié']),
                summary: 'Obtenir le profil de l\'utilisateur courant',
                security: $secured,
            ),
        ));

        // ── /api/auth/verify-email/{token} ────────────────────────────────
        $openApi->getPaths()->addPath('/api/auth/verify-email/{token}', new PathItem(
            get: new Operation(
                operationId: 'authVerifyEmail',
                tags: ['Auth'],
                responses: $responses([200 => 'Email vérifié', 400 => 'Token invalide']),
                summary: 'Vérifier l\'adresse email via le token reçu par email',
                parameters: [
                    new Parameter('token', 'path', 'Token de vérification', required: true, schema: ['type' => 'string']),
                ],
                security: $public,
            ),
        ));

        // ── /api/users/{id} GET ───────────────────────────────────────────
        $idParam = new Parameter('id', 'path', 'UUID de l\'utilisateur', required: true, schema: ['type' => 'string', 'format' => 'uuid']);

        $openApi->getPaths()->addPath('/api/users/{id}', new PathItem(
            get: new Operation(
                operationId: 'userGet',
                tags: ['Users'],
                responses: $responses([200 => 'Profil utilisateur', 403 => 'Accès refusé', 404 => 'Introuvable']),
                summary: 'Obtenir le profil utilisateur',
                parameters: [$idParam],
                security: $secured,
            ),
            patch: new Operation(
                operationId: 'userPatch',
                tags: ['Users'],
                responses: $responses([200 => 'Profil mis à jour', 403 => 'Accès refusé', 404 => 'Introuvable']),
                summary: 'Mettre à jour le profil (firstName, lastName)',
                parameters: [$idParam],
                requestBody: new RequestBody(
                    description: 'Champs à mettre à jour',
                    content: new \ArrayObject(['application/merge-patch+json' => new MediaType(new \ArrayObject([
                        'type' => 'object',
                        'properties' => [
                            'firstName' => ['type' => 'string'],
                            'lastName'  => ['type' => 'string'],
                        ],
                    ]))]),
                    required: true,
                ),
                security: $secured,
            ),
            delete: new Operation(
                operationId: 'userDelete',
                tags: ['Users'],
                responses: $responses([204 => 'Compte supprimé (RGPD)', 403 => 'Accès refusé', 404 => 'Introuvable']),
                summary: 'Supprimer le compte (droit à l\'oubli RGPD)',
                parameters: [$idParam],
                security: $secured,
            ),
        ));

        // ── /api/users/{id}/export ────────────────────────────────────────
        $openApi->getPaths()->addPath('/api/users/{id}/export', new PathItem(
            get: new Operation(
                operationId: 'userExport',
                tags: ['Users'],
                responses: $responses([200 => 'Export RGPD des données utilisateur', 403 => 'Accès refusé', 404 => 'Introuvable']),
                summary: 'Exporter toutes les données personnelles (RGPD)',
                parameters: [$idParam],
                security: $secured,
            ),
        ));

        // ═══ Sprint 2 — Catalogue ═══════════════════════════════════════════
        $catalogueId = new Parameter('id', 'path', 'UUID', required: true, schema: ['type' => 'string', 'format' => 'uuid']);
        $pageParam   = new Parameter('page', 'query', 'Numéro de page (défaut 1)', required: false, schema: ['type' => 'integer', 'minimum' => 1]);
        $limitParam  = new Parameter('limit', 'query', 'Taille de page (défaut 30, max 100)', required: false, schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]);

        // ── /api/films ────────────────────────────────────────────────────
        $openApi->getPaths()->addPath('/api/films', new PathItem(
            get: new Operation(
                operationId: 'filmList',
                tags: ['Catalogue — Films'],
                responses: $responses([200 => 'Liste paginée de films']),
                summary: 'Liste paginée des films',
                parameters: [$pageParam, $limitParam],
                security: $public,
            ),
        ));

        // ── /api/films/search ─────────────────────────────────────────────
        $openApi->getPaths()->addPath('/api/films/search', new PathItem(
            get: new Operation(
                operationId: 'filmSearch',
                tags: ['Catalogue — Films'],
                responses: $responses([200 => 'Résultats paginés']),
                summary: 'Rechercher des films (q + filtres)',
                parameters: [
                    new Parameter('q',       'query', 'Terme de recherche',                 required: false, schema: ['type' => 'string']),
                    new Parameter('genre',   'query', 'Slug, nom ou UUID du genre',         required: false, schema: ['type' => 'string']),
                    new Parameter('year',    'query', 'Année de sortie',                    required: false, schema: ['type' => 'integer']),
                    new Parameter('country', 'query', 'Code ISO 2 ou UUID du pays',         required: false, schema: ['type' => 'string']),
                    new Parameter('lang',    'query', 'Code ISO ou UUID de la langue',      required: false, schema: ['type' => 'string']),
                    $pageParam, $limitParam,
                ],
                security: $public,
            ),
        ));

        // ── /api/films/featured ───────────────────────────────────────────
        $openApi->getPaths()->addPath('/api/films/featured', new PathItem(
            get: new Operation(
                operationId: 'filmFeatured',
                tags: ['Catalogue — Films'],
                responses: $responses([200 => 'Films mis en avant actifs']),
                summary: 'Contenus mis en avant (FeaturedContent actifs)',
                security: $public,
            ),
        ));

        // ── /api/films/trending ───────────────────────────────────────────
        $openApi->getPaths()->addPath('/api/films/trending', new PathItem(
            get: new Operation(
                operationId: 'filmTrending',
                tags: ['Catalogue — Films'],
                responses: $responses([200 => 'Films les plus vus']),
                summary: 'Films tendances (tri par vues décroissantes)',
                parameters: [new Parameter('limit', 'query', 'Nombre max (défaut 10, max 50)', required: false, schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 50])],
                security: $public,
            ),
        ));

        // ── /api/films/new ────────────────────────────────────────────────
        $openApi->getPaths()->addPath('/api/films/new', new PathItem(
            get: new Operation(
                operationId: 'filmNew',
                tags: ['Catalogue — Films'],
                responses: $responses([200 => 'Films récents']),
                summary: 'Nouveautés (tri par createdAt DESC)',
                parameters: [new Parameter('limit', 'query', 'Nombre max (défaut 10, max 50)', required: false, schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 50])],
                security: $public,
            ),
        ));

        // ── /api/films/{id} ───────────────────────────────────────────────
        $openApi->getPaths()->addPath('/api/films/{id}', new PathItem(
            get: new Operation(
                operationId: 'filmGet',
                tags: ['Catalogue — Films'],
                responses: $responses([200 => 'Fiche film', 404 => 'Introuvable']),
                summary: 'Détails d\'un film',
                parameters: [$catalogueId],
                security: $public,
            ),
            patch: new Operation(
                operationId: 'filmPatch',
                tags: ['Catalogue — Films'],
                responses: $responses([200 => 'Film mis à jour', 403 => 'Accès refusé', 404 => 'Introuvable']),
                summary: 'Mettre à jour un film (ADMIN)',
                parameters: [$catalogueId],
                requestBody: $jsonBody(['type' => 'object', 'properties' => [
                    'title'          => ['type' => 'string'],
                    'synopsis'       => ['type' => 'string'],
                    'year'           => ['type' => 'integer'],
                    'duration'       => ['type' => 'integer'],
                    'poster'         => ['type' => 'string', 'nullable' => true],
                    'trailerVideoId' => ['type' => 'string', 'nullable' => true],
                    'bunnyVideoId'   => ['type' => 'string', 'nullable' => true],
                    'genres'         => ['type' => 'array', 'items' => ['type' => 'string']],
                    'countries'      => ['type' => 'array', 'items' => ['type' => 'string']],
                    'directors'      => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid']],
                    'cast'           => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid']],
                ]]),
                security: $secured,
            ),
            delete: new Operation(
                operationId: 'filmDelete',
                tags: ['Catalogue — Films'],
                responses: $responses([204 => 'Film supprimé', 403 => 'Accès refusé', 404 => 'Introuvable']),
                summary: 'Supprimer un film (ADMIN)',
                parameters: [$catalogueId],
                security: $secured,
            ),
        ));

        // ── /api/films/{id}/view ──────────────────────────────────────────
        $openApi->getPaths()->addPath('/api/films/{id}/view', new PathItem(
            post: new Operation(
                operationId: 'filmView',
                tags: ['Catalogue — Films'],
                responses: $responses([204 => 'Compteur incrémenté', 401 => 'Non authentifié', 404 => 'Introuvable']),
                summary: 'Incrémenter le compteur de vues',
                parameters: [$catalogueId],
                security: $secured,
            ),
        ));

        // ── /api/films/{id}/stream ────────────────────────────────────────
        $openApi->getPaths()->addPath('/api/films/{id}/stream', new PathItem(
            get: new Operation(
                operationId: 'filmStream',
                tags: ['Catalogue — Films'],
                responses: $responses([200 => 'Retourne {bunnyVideoId, libraryId} pour construire l\'URL HLS côté client', 401 => 'Non authentifié', 404 => 'Introuvable']),
                summary: 'Infos de streaming Bunny Stream',
                parameters: [$catalogueId],
                security: $secured,
            ),
        ));

        // ── /api/series ───────────────────────────────────────────────────
        $openApi->getPaths()->addPath('/api/series', new PathItem(
            get: new Operation(
                operationId: 'serieList',
                tags: ['Catalogue — Séries'],
                responses: $responses([200 => 'Liste paginée de séries']),
                summary: 'Liste paginée des séries',
                parameters: [$pageParam, $limitParam],
                security: $public,
            ),
        ));

        // ── /api/series/search ────────────────────────────────────────────
        $openApi->getPaths()->addPath('/api/series/search', new PathItem(
            get: new Operation(
                operationId: 'serieSearch',
                tags: ['Catalogue — Séries'],
                responses: $responses([200 => 'Résultats paginés']),
                summary: 'Rechercher des séries',
                parameters: [
                    new Parameter('q',       'query', 'Terme de recherche',            required: false, schema: ['type' => 'string']),
                    new Parameter('genre',   'query', 'Slug, nom ou UUID du genre',    required: false, schema: ['type' => 'string']),
                    new Parameter('year',    'query', 'Année',                          required: false, schema: ['type' => 'integer']),
                    new Parameter('country', 'query', 'Code ISO 2 ou UUID du pays',    required: false, schema: ['type' => 'string']),
                    new Parameter('lang',    'query', 'Code ISO ou UUID de la langue', required: false, schema: ['type' => 'string']),
                    $pageParam, $limitParam,
                ],
                security: $public,
            ),
        ));

        // ── /api/series/{id} ──────────────────────────────────────────────
        $openApi->getPaths()->addPath('/api/series/{id}', new PathItem(
            get: new Operation(
                operationId: 'serieGet',
                tags: ['Catalogue — Séries'],
                responses: $responses([200 => 'Fiche série', 404 => 'Introuvable']),
                summary: 'Détails d\'une série',
                parameters: [$catalogueId],
                security: $public,
            ),
            patch: new Operation(
                operationId: 'seriePatch',
                tags: ['Catalogue — Séries'],
                responses: $responses([200 => 'Série mise à jour', 403 => 'Accès refusé', 404 => 'Introuvable']),
                summary: 'Mettre à jour une série (ADMIN)',
                parameters: [$catalogueId],
                requestBody: $jsonBody(['type' => 'object', 'properties' => [
                    'title'     => ['type' => 'string'],
                    'synopsis'  => ['type' => 'string'],
                    'year'      => ['type' => 'integer'],
                    'poster'    => ['type' => 'string', 'nullable' => true],
                    'genres'    => ['type' => 'array', 'items' => ['type' => 'string']],
                    'countries' => ['type' => 'array', 'items' => ['type' => 'string']],
                ]]),
                security: $secured,
            ),
            delete: new Operation(
                operationId: 'serieDelete',
                tags: ['Catalogue — Séries'],
                responses: $responses([204 => 'Série supprimée', 403 => 'Accès refusé', 404 => 'Introuvable']),
                summary: 'Supprimer une série (ADMIN)',
                parameters: [$catalogueId],
                security: $secured,
            ),
        ));

        // ── /api/series/{id}/seasons ──────────────────────────────────────
        $openApi->getPaths()->addPath('/api/series/{id}/seasons', new PathItem(
            get: new Operation(
                operationId: 'serieSeasons',
                tags: ['Catalogue — Séries'],
                responses: $responses([200 => 'Saisons de la série', 404 => 'Série introuvable']),
                summary: 'Lister les saisons d\'une série',
                parameters: [$catalogueId],
                security: $public,
            ),
        ));

        // ── /api/series/{id}/seasons/{number}/episodes ────────────────────
        $openApi->getPaths()->addPath('/api/series/{id}/seasons/{number}/episodes', new PathItem(
            get: new Operation(
                operationId: 'serieSeasonEpisodes',
                tags: ['Catalogue — Séries'],
                responses: $responses([200 => 'Épisodes de la saison', 404 => 'Saison introuvable']),
                summary: 'Lister les épisodes d\'une saison',
                parameters: [
                    $catalogueId,
                    new Parameter('number', 'path', 'Numéro de la saison', required: true, schema: ['type' => 'integer', 'minimum' => 1]),
                ],
                security: $public,
            ),
        ));

        // ── /api/episodes/{id} ────────────────────────────────────────────
        $openApi->getPaths()->addPath('/api/episodes/{id}', new PathItem(
            get: new Operation(
                operationId: 'episodeGet',
                tags: ['Catalogue — Épisodes'],
                responses: $responses([200 => 'Épisode', 401 => 'Non authentifié', 404 => 'Introuvable']),
                summary: 'Détails d\'un épisode',
                parameters: [$catalogueId],
                security: $secured,
            ),
        ));

        // ── /api/episodes/{id}/stream ─────────────────────────────────────
        $openApi->getPaths()->addPath('/api/episodes/{id}/stream', new PathItem(
            get: new Operation(
                operationId: 'episodeStream',
                tags: ['Catalogue — Épisodes'],
                responses: $responses([200 => 'Retourne {bunnyVideoId, libraryId}', 401 => 'Non authentifié', 404 => 'Introuvable']),
                summary: 'Infos de streaming Bunny Stream',
                parameters: [$catalogueId],
                security: $secured,
            ),
        ));

        // ── /api/genres ───────────────────────────────────────────────────
        $openApi->getPaths()->addPath('/api/genres', new PathItem(
            get: new Operation(
                operationId: 'genresList',
                tags: ['Catalogue — Référence'],
                responses: $responses([200 => 'Liste des genres']),
                summary: 'Lister les genres',
                security: $public,
            ),
        ));

        // ── /api/countries ────────────────────────────────────────────────
        $openApi->getPaths()->addPath('/api/countries', new PathItem(
            get: new Operation(
                operationId: 'countriesList',
                tags: ['Catalogue — Référence'],
                responses: $responses([200 => 'Liste des pays']),
                summary: 'Lister les pays',
                security: $public,
            ),
        ));

        // ── /api/languages ────────────────────────────────────────────────
        $openApi->getPaths()->addPath('/api/languages', new PathItem(
            get: new Operation(
                operationId: 'languagesList',
                tags: ['Catalogue — Référence'],
                responses: $responses([200 => 'Liste des langues']),
                summary: 'Lister les langues',
                security: $public,
            ),
        ));

        // ── /api/persons ──────────────────────────────────────────────────
        $openApi->getPaths()->addPath('/api/persons', new PathItem(
            get: new Operation(
                operationId: 'personsList',
                tags: ['Catalogue — Référence'],
                responses: $responses([200 => 'Liste des personnes (réalisateurs/acteurs)']),
                summary: 'Lister les personnes',
                security: $public,
            ),
        ));

        return $openApi;
    }
}
