<?php

namespace App\OpenApi;

/**
 * ============================================================
 * CINAF v2 — Descriptions des routes pour la documentation OpenAPI
 * ============================================================
 * Table « MÉTHODE /chemin » → description, lue par `RouteOpenApiFactory`
 * pour documenter les routes des contrôleurs Symfony qui ne sont pas
 * décrites à la main dans `OpenApiFactory` (Admin, Studio, catalogue
 * Découvrir, studios publics, abonnements, Stripe).
 *
 * Seule la partie rédactionnelle vit ici. Le reste est déduit du routeur par
 * la fabrique : paramètres de chemin, jeton JWT requis et rôle exigé
 * (attributs #[IsGranted] + règles access_control).
 *
 * Clés d'une entrée (seules `tag` et `summary` sont obligatoires) :
 *  - tag       : groupe affiché dans Swagger UI ;
 *  - summary   : résumé d'une ligne, en français ;
 *  - query     : paramètres de requête, nom => [type, description] ;
 *  - body      : corps JSON, nom => [type, description] ; une chaîne décrit
 *                un corps libre (ex. événement Stripe brut) ;
 *  - multipart : comme `body`, envoyé en multipart/form-data (upload) ;
 *  - required  : champs obligatoires de `body` / `multipart` ;
 *  - headers   : en-têtes obligatoires, nom => description ;
 *  - status    : code de succès s'il diffère de 200 ;
 *  - responses : autres réponses notables, code => description.
 * Types : string, integer, boolean, uuid, date-time, binary, string[],
 * enum:A,B,C, et le suffixe « |null » pour un champ que l'on peut effacer.
 *
 * ⚠️ Toute nouvelle route /api doit recevoir une entrée ici : sinon elle
 * apparaît quand même dans la documentation, mais avec un résumé « à
 * compléter », et le test `OpenApiRouteCoverageTest` échoue.
 */
final class RouteDocumentation
{
    /**
     * @return array<string, array<string, mixed>> Descriptions indexées par
     *         « MÉTHODE /chemin », chemin tel que déclaré dans #[Route]
     */
    public static function all(): array
    {
        // Groupes Swagger UI, un par zone fonctionnelle.
        $discover = 'Catalogue — Découvrir';
        $studiosPublic = 'Studios — Chaînes publiques';
        $subscriptions = 'Abonnements';
        $stripe = 'Stripe';
        $studioAccount = 'Studio — Mon studio';
        $studioFilms = 'Studio — Films';
        $studioSeries = 'Studio — Séries';
        $studioUpload = 'Studio — Upload';
        $adminUsers = 'Admin — Utilisateurs';
        $adminSubscriptions = 'Admin — Abonnements';
        $adminFilms = 'Admin — Films';
        $adminSeries = 'Admin — Séries';
        $adminApprovals = 'Admin — Approbations';
        $adminWithdrawals = 'Admin — Retraits';
        $adminStudios = 'Admin — Studios';
        $adminBunny = 'Admin — Bunny Storage';

        // Fragments partagés par plusieurs routes.
        $page = ['page' => ['integer', 'Numéro de page (défaut 1)']];
        $limit20 = ['limit' => ['integer', 'Éléments par page (défaut 20, max 100)']];
        $limit30 = ['limit' => ['integer', 'Éléments par page (défaut 30, max 100)']];
        $itemsPerPage = ['itemsPerPage' => ['integer', 'Éléments par page (défaut 30, max 100)']];
        // Le studio voit ses contenus en attente ; l'admin ne filtre et
        // n'impose que les trois statuts « définitifs ».
        $studioStatus = ['status' => ['enum:DRAFT,PENDING_APPROVAL,PUBLISHED,WITHDRAWN', 'Filtrer par statut']];
        $adminStatus = 'enum:DRAFT,PUBLISHED,WITHDRAWN';
        $adminFilters = [
            'status' => [$adminStatus, 'Filtrer par statut'],
            'studioId' => ['uuid', 'Filtrer par studio'],
            'search' => ['string', 'Recherche dans le titre'],
        ];
        $bunnyListing = [
            'zone' => ['string', 'Storage Zone (défaut : zone configurée par défaut)'],
            'path' => ['string', 'Dossier à lister (défaut : racine)'],
        ];
        $reviewNote = ['reviewNote' => ['string', "Note de l'administrateur (corps facultatif)"]];
        $bunnyPathConflict = 'Chemin Bunny hors du dossier du studio, ou contenu importé';
        $pendingFrozen = "Contenu en attente d'approbation : modification impossible";
        $notDraft = 'Contenu déjà publié : passer par une demande de retrait';
        $notPending = "Contenu pas en attente d'approbation";
        $publishOnlyDraft = 'Seul un brouillon (DRAFT) peut être publié';
        $withdrawalPending = 'Une demande de retrait est déjà en attente pour ce contenu';
        $bunnyDown = 'Source Bunny injoignable (mode CATALOGUE_SOURCE=bunny)';

        return [
            // ══ Catalogue public « Découvrir » ═══════════════════════════════
            'GET /api/catalogue/discover' => [
                'tag' => $discover,
                'summary' => 'Lister les œuvres publiées (films et séries) du catalogue public',
                'query' => [
                    'q' => ['string', 'Recherche dans le titre'],
                    'kind' => ['enum:film,serie', 'Restreindre aux films ou aux séries'],
                    ...$page,
                    'limit' => ['integer', 'Éléments par page (défaut 30)'],
                ],
                'responses' => [502 => $bunnyDown],
            ],
            'GET /api/catalogue/discover/{slug}' => [
                'tag' => $discover,
                'summary' => "Détail d'une œuvre : saisons, épisodes et URL de lecture HLS",
                'responses' => [502 => $bunnyDown],
            ],
            'GET /api/catalogue/discover/{slug}/can-play' => [
                'tag' => $discover,
                'summary' => "Vérifier que l'utilisateur connecté peut lire l'œuvre (abonnement actif)",
                'status' => 204,
                'responses' => [403 => 'Aucun abonnement actif', 502 => $bunnyDown],
            ],

            // ══ Studios publics (vue « chaîne ») ═════════════════════════════
            'GET /api/studios' => [
                'tag' => $studiosPublic,
                'summary' => 'Lister les studios',
                'query' => [...$page, ...$itemsPerPage],
            ],
            'GET /api/studios/search' => [
                'tag' => $studiosPublic,
                'summary' => 'Rechercher un studio par son nom',
                'query' => ['q' => ['string', 'Terme recherché (liste vide si absent)']],
            ],
            'GET /api/studios/{slug}' => [
                'tag' => $studiosPublic,
                'summary' => "Profil public d'un studio",
            ],
            'GET /api/studios/{slug}/works' => [
                'tag' => $studiosPublic,
                'summary' => 'Œuvres publiées par un studio',
                'query' => [...$page, ...$itemsPerPage, 'kind' => ['enum:all,film,serie', "Type d'œuvre (défaut all)"]],
            ],
            'POST /api/studios/{slug}/subscribe' => [
                'tag' => $studiosPublic,
                'summary' => "S'abonner à un studio",
                'status' => 201,
                'responses' => [200 => 'Déjà abonné (opération idempotente)'],
            ],
            'DELETE /api/studios/{slug}/subscribe' => [
                'tag' => $studiosPublic,
                'summary' => "Se désabonner d'un studio",
                'status' => 204,
            ],
            'GET /api/studios/{slug}/subscription' => [
                'tag' => $studiosPublic,
                'summary' => "Savoir si l'utilisateur connecté est abonné au studio",
            ],

            // ══ Abonnements (utilisateur) ════════════════════════════════════
            'GET /api/subscription-plans' => [
                'tag' => $subscriptions,
                'summary' => "Lister les plans d'abonnement disponibles",
            ],
            'GET /api/subscription-plans/{id}' => [
                'tag' => $subscriptions,
                'summary' => "Détail d'un plan d'abonnement",
                'responses' => [400 => 'Identifiant invalide'],
            ],
            'GET /api/subscriptions/current' => [
                'tag' => $subscriptions,
                'summary' => "Abonnement en cours de l'utilisateur connecté (null si aucun)",
            ],
            'POST /api/subscriptions/subscribe' => [
                'tag' => $subscriptions,
                'summary' => 'Souscrire à un plan (Stripe Embedded Checkout, ou activation immédiate en mode simulé)',
                'body' => ['planId' => ['uuid', 'Plan choisi (voir GET /api/subscription-plans)']],
                'required' => ['planId'],
                'status' => 201,
                'responses' => [
                    200 => 'Mode Stripe : {mode, clientSecret, sessionId} pour afficher le formulaire de paiement',
                    404 => 'Plan introuvable',
                ],
            ],
            'GET /api/subscriptions/session/{sessionId}' => [
                'tag' => $subscriptions,
                'summary' => "Statut d'une session Stripe Checkout (page de retour après paiement)",
                'responses' => [503 => 'Stripe désactivé sur cette instance'],
            ],
            'POST /api/subscriptions/cancel' => [
                'tag' => $subscriptions,
                'summary' => "Résilier l'abonnement à la fin de la période payée",
                'responses' => [404 => 'Aucun abonnement actif'],
            ],
            'POST /api/subscriptions/resume' => [
                'tag' => $subscriptions,
                'summary' => 'Annuler une résiliation programmée',
                'responses' => [404 => 'Aucun abonnement actif', 409 => "L'abonnement n'est pas en cours de résiliation"],
            ],
            'GET /api/subscriptions/payments' => [
                'tag' => $subscriptions,
                'summary' => "Historique des factures de l'utilisateur connecté",
            ],

            // ══ Stripe ═══════════════════════════════════════════════════════
            'POST /api/stripe/webhook' => [
                'tag' => $stripe,
                'summary' => 'Webhook Stripe (appelé par Stripe, jamais par le front)',
                'headers' => ['Stripe-Signature' => "Signature HMAC de l'événement, vérifiée avec STRIPE_WEBHOOK_SECRET"],
                'body' => 'Événement Stripe brut : checkout.session.completed, customer.subscription.updated ou customer.subscription.deleted',
                'responses' => [400 => 'Signature invalide', 503 => 'Stripe désactivé sur cette instance'],
            ],

            // ══ Studio — compte ══════════════════════════════════════════════
            'POST /api/studio/onboarding' => [
                'tag' => $studioAccount,
                'summary' => 'Devenir studio : créer son studio (non validé) et obtenir ROLE_CREATEUR',
                'body' => [
                    'name' => ['string', 'Nom du studio'],
                    'description' => ['string', 'Présentation du studio'],
                ],
                'required' => ['name', 'description'],
                'status' => 201,
                'responses' => [
                    403 => 'Un administrateur ne peut pas créer de studio',
                    409 => 'Studio déjà existant, ou nom déjà utilisé',
                ],
            ],
            'GET /api/studio/me' => [
                'tag' => $studioAccount,
                'summary' => 'Mon studio et ses statistiques (contenus par statut, retraits en attente)',
            ],
            'PATCH /api/studio/me' => [
                'tag' => $studioAccount,
                'summary' => 'Modifier le nom ou la description de mon studio',
                'body' => [
                    'name' => ['string', 'Nom (255 caractères max)'],
                    'description' => ['string', 'Description (2 000 caractères max)'],
                ],
                'responses' => [409 => 'Nom de studio déjà utilisé'],
            ],

            // ══ Studio — films ═══════════════════════════════════════════════
            'GET /api/studio/films' => [
                'tag' => $studioFilms,
                'summary' => 'Lister les films de mon studio',
                'query' => [...$page, ...$limit30, ...$studioStatus],
            ],
            'GET /api/studio/films/{id}' => [
                'tag' => $studioFilms,
                'summary' => "Détail d'un de mes films",
            ],
            'POST /api/studio/films' => [
                'tag' => $studioFilms,
                'summary' => 'Créer un film (statut DRAFT)',
                'body' => [
                    'title' => ['string', 'Titre'],
                    'synopsis' => ['string', 'Synopsis'],
                    'year' => ['integer', 'Année de sortie'],
                    'duration' => ['integer', 'Durée en minutes'],
                    'slug' => ['string', 'Slug (dérivé du titre si absent)'],
                    'bunnyVideoId' => ['string', 'Chemin Bunny de la vidéo, dans studios/{slug-du-studio}/'],
                ],
                'required' => ['title', 'synopsis', 'year', 'duration'],
                'status' => 201,
                'responses' => [403 => 'Chemin Bunny hors du dossier du studio'],
            ],
            'PATCH /api/studio/films/{id}' => [
                'tag' => $studioFilms,
                'summary' => 'Modifier un de mes films',
                'body' => [
                    'title' => ['string', 'Titre'],
                    'synopsis' => ['string', 'Synopsis'],
                    'year' => ['integer', 'Année de sortie'],
                    'duration' => ['integer', 'Durée en minutes'],
                    'poster' => ['string|null', "URL de l'affiche"],
                    'trailerVideoId' => ['string|null', 'Chemin Bunny de la bande-annonce'],
                    'bunnyVideoId' => ['string|null', 'Chemin Bunny de la vidéo, dans studios/{slug-du-studio}/'],
                ],
                'responses' => [403 => $bunnyPathConflict, 409 => $pendingFrozen],
            ],
            'DELETE /api/studio/films/{id}' => [
                'tag' => $studioFilms,
                'summary' => 'Supprimer un de mes films (brouillon uniquement)',
                'status' => 204,
                'responses' => [409 => $notDraft],
            ],
            'POST /api/studio/films/{id}/publish' => [
                'tag' => $studioFilms,
                'summary' => "Publier un film (PENDING_APPROVAL tant que le studio n'est pas validé)",
                'responses' => [400 => $publishOnlyDraft],
            ],
            'POST /api/studio/films/{id}/withdraw' => [
                'tag' => $studioFilms,
                'summary' => "Demander le retrait d'un film publié (validé par un administrateur)",
                'body' => ['reason' => ['string', 'Motif de la demande']],
                'required' => ['reason'],
                'status' => 201,
                'responses' => [409 => $withdrawalPending],
            ],

            // ══ Studio — séries, saisons, épisodes ═══════════════════════════
            'GET /api/studio/series' => [
                'tag' => $studioSeries,
                'summary' => 'Lister les séries de mon studio',
                'query' => [...$page, ...$limit30, ...$studioStatus],
            ],
            'GET /api/studio/series/{id}' => [
                'tag' => $studioSeries,
                'summary' => "Détail d'une de mes séries, avec ses saisons et épisodes",
            ],
            'POST /api/studio/series' => [
                'tag' => $studioSeries,
                'summary' => 'Créer une série (statut DRAFT)',
                'body' => [
                    'title' => ['string', 'Titre'],
                    'synopsis' => ['string', 'Synopsis'],
                    'year' => ['integer', 'Année de sortie'],
                    'slug' => ['string', 'Slug (dérivé du titre si absent)'],
                ],
                'required' => ['title', 'synopsis', 'year'],
                'status' => 201,
            ],
            'PATCH /api/studio/series/{id}' => [
                'tag' => $studioSeries,
                'summary' => 'Modifier une de mes séries',
                'body' => [
                    'title' => ['string', 'Titre'],
                    'synopsis' => ['string', 'Synopsis'],
                    'year' => ['integer', 'Année de sortie'],
                    'poster' => ['string|null', "URL de l'affiche"],
                    'trailerVideoId' => ['string|null', 'Chemin Bunny de la bande-annonce'],
                ],
                'responses' => [409 => $pendingFrozen],
            ],
            'DELETE /api/studio/series/{id}' => [
                'tag' => $studioSeries,
                'summary' => 'Supprimer une de mes séries (brouillon uniquement)',
                'status' => 204,
                'responses' => [409 => $notDraft],
            ],
            'POST /api/studio/series/{id}/publish' => [
                'tag' => $studioSeries,
                'summary' => "Publier une série (PENDING_APPROVAL tant que le studio n'est pas validé)",
                'responses' => [400 => $publishOnlyDraft],
            ],
            'POST /api/studio/series/{id}/withdraw' => [
                'tag' => $studioSeries,
                'summary' => "Demander le retrait d'une série publiée (validé par un administrateur)",
                'body' => ['reason' => ['string', 'Motif de la demande']],
                'required' => ['reason'],
                'status' => 201,
                'responses' => [409 => $withdrawalPending],
            ],
            'POST /api/studio/series/{serieId}/seasons' => [
                'tag' => $studioSeries,
                'summary' => 'Ajouter une saison à une de mes séries',
                'body' => [
                    'number' => ['integer', 'Numéro de la saison'],
                    'title' => ['string', 'Titre'],
                    'synopsis' => ['string', 'Synopsis'],
                ],
                'required' => ['number'],
                'status' => 201,
            ],
            'PATCH /api/studio/series/{serieId}/seasons/{seasonId}' => [
                'tag' => $studioSeries,
                'summary' => 'Modifier une saison',
                'body' => [
                    'number' => ['integer', 'Numéro de la saison'],
                    'title' => ['string|null', 'Titre'],
                    'synopsis' => ['string|null', 'Synopsis'],
                ],
            ],
            'DELETE /api/studio/series/{serieId}/seasons/{seasonId}' => [
                'tag' => $studioSeries,
                'summary' => 'Supprimer une saison',
                'status' => 204,
            ],
            'POST /api/studio/series/{serieId}/seasons/{seasonId}/episodes' => [
                'tag' => $studioSeries,
                'summary' => 'Ajouter un épisode à une saison',
                'body' => [
                    'number' => ['integer', "Numéro de l'épisode (≥ 1, unique dans la saison)"],
                    'title' => ['string', 'Titre'],
                    'synopsis' => ['string', 'Synopsis'],
                    'duration' => ['integer', 'Durée en minutes'],
                    'bunnyVideoId' => ['string', 'Chemin Bunny de la vidéo, dans studios/{slug-du-studio}/'],
                ],
                'required' => ['number', 'title'],
                'status' => 201,
                'responses' => [403 => 'Chemin Bunny hors du dossier du studio', 409 => 'Numéro déjà utilisé dans cette saison'],
            ],
            'PATCH /api/studio/series/{serieId}/seasons/{seasonId}/episodes/{episodeId}' => [
                'tag' => $studioSeries,
                'summary' => 'Modifier un épisode',
                'body' => [
                    'number' => ['integer', "Numéro de l'épisode (≥ 1, unique dans la saison)"],
                    'title' => ['string', 'Titre'],
                    'synopsis' => ['string|null', 'Synopsis'],
                    'duration' => ['integer', 'Durée en minutes'],
                    'bunnyVideoId' => ['string|null', 'Chemin Bunny de la vidéo, dans studios/{slug-du-studio}/'],
                ],
                'responses' => [403 => $bunnyPathConflict, 409 => 'Numéro déjà utilisé dans cette saison'],
            ],
            'DELETE /api/studio/series/{serieId}/seasons/{seasonId}/episodes/{episodeId}' => [
                'tag' => $studioSeries,
                'summary' => 'Supprimer un épisode',
                'status' => 204,
            ],

            // ══ Studio — upload Bunny ════════════════════════════════════════
            'POST /api/studio/upload' => [
                'tag' => $studioUpload,
                'summary' => 'Téléverser une affiche, une bande-annonce ou une vidéo sur Bunny (remplace le fichier précédent)',
                'multipart' => [
                    'file' => ['binary', 'Image jpg/png/webp (10 Mo max) ou vidéo mp4/mov/webm (3 Go max)'],
                    'targetType' => ['enum:film,serie,episode', 'Type de la cible'],
                    'targetId' => ['uuid', 'Identifiant de la cible'],
                    'purpose' => ['enum:poster,trailer,video', 'Usage du fichier (épisode : video uniquement)'],
                ],
                'required' => ['file', 'targetType', 'targetId', 'purpose'],
                'status' => 201,
                'responses' => [
                    404 => 'Cible introuvable',
                    413 => 'Fichier trop volumineux',
                    415 => 'Type ou extension de fichier non supporté',
                    502 => "Échec de l'envoi vers Bunny",
                ],
            ],

            // ══ Admin — utilisateurs ═════════════════════════════════════════
            'GET /api/admin/users' => [
                'tag' => $adminUsers,
                'summary' => 'Lister les utilisateurs',
                'query' => [
                    ...$page,
                    ...$limit20,
                    'search' => ['string', 'Recherche sur email, prénom ou nom'],
                    'role' => ['enum:ROLE_USER,ROLE_ABONNE,ROLE_CREATEUR,ROLE_MODERATEUR,ROLE_ADMIN', 'Filtrer par rôle'],
                ],
            ],
            'GET /api/admin/users/export' => [
                'tag' => $adminUsers,
                'summary' => 'Exporter tous les utilisateurs au format CSV',
            ],
            'GET /api/admin/users/{id}' => [
                'tag' => $adminUsers,
                'summary' => "Détail d'un utilisateur",
            ],
            'PATCH /api/admin/users/{id}' => [
                'tag' => $adminUsers,
                'summary' => "Modifier l'email, le nom ou la vérification d'email d'un utilisateur",
                'body' => [
                    'email' => ['string', 'Adresse email'],
                    'firstName' => ['string', 'Prénom'],
                    'lastName' => ['string', 'Nom'],
                    'isVerified' => ['boolean', 'Email vérifié'],
                ],
                'responses' => [409 => 'Email déjà utilisé'],
            ],
            'DELETE /api/admin/users/{id}' => [
                'tag' => $adminUsers,
                'summary' => 'Supprimer définitivement un utilisateur (pas son propre compte)',
                'status' => 204,
            ],
            'PATCH /api/admin/users/{id}/suspend' => [
                'tag' => $adminUsers,
                'summary' => 'Suspendre un compte (pas le sien)',
            ],
            'PATCH /api/admin/users/{id}/activate' => [
                'tag' => $adminUsers,
                'summary' => 'Réactiver un compte suspendu',
            ],
            'PATCH /api/admin/users/{id}/role' => [
                'tag' => $adminUsers,
                'summary' => "Remplacer les rôles d'un utilisateur",
                'body' => ['roles' => ['string[]', 'Parmi ROLE_USER, ROLE_ABONNE, ROLE_CREATEUR, ROLE_MODERATEUR, ROLE_ADMIN']],
                'required' => ['roles'],
            ],

            // ══ Admin — abonnements d'un utilisateur ═════════════════════════
            'GET /api/admin/users/{id}/subscription' => [
                'tag' => $adminSubscriptions,
                'summary' => "Abonnement en cours d'un utilisateur (null si aucun)",
            ],
            'POST /api/admin/users/{id}/subscription' => [
                'tag' => $adminSubscriptions,
                'summary' => 'Attribuer un abonnement à un utilisateur',
                'body' => ['planId' => ['uuid', 'Plan attribué']],
                'required' => ['planId'],
                'status' => 201,
            ],
            'PATCH /api/admin/users/{id}/subscription' => [
                'tag' => $adminSubscriptions,
                'summary' => "Changer le plan ou la date de début d'un abonnement (date de fin recalculée)",
                'body' => [
                    'planId' => ['uuid', 'Nouveau plan'],
                    'startsAt' => ['date-time', 'Nouvelle date de début (ISO 8601)'],
                ],
            ],
            'DELETE /api/admin/users/{id}/subscription' => [
                'tag' => $adminSubscriptions,
                'summary' => "Résilier l'abonnement d'un utilisateur à la fin de la période payée",
            ],
            'POST /api/admin/users/{id}/subscription/resume' => [
                'tag' => $adminSubscriptions,
                'summary' => "Annuler la résiliation programmée d'un utilisateur",
                'responses' => [409 => "L'abonnement n'est pas en cours de résiliation"],
            ],
            'GET /api/admin/users/{id}/payments' => [
                'tag' => $adminSubscriptions,
                'summary' => "Historique des factures d'un utilisateur",
            ],

            // ══ Admin — films et séries ══════════════════════════════════════
            'GET /api/admin/films' => [
                'tag' => $adminFilms,
                'summary' => 'Lister tous les films, tous studios confondus',
                'query' => [...$page, ...$limit20, ...$adminFilters],
            ],
            'GET /api/admin/films/{id}' => [
                'tag' => $adminFilms,
                'summary' => "Détail d'un film avec son studio",
            ],
            'PATCH /api/admin/films/{id}' => [
                'tag' => $adminFilms,
                'summary' => 'Modifier un film : métadonnées, statut ou studio',
                'body' => [
                    'title' => ['string', 'Titre (non vide)'],
                    'synopsis' => ['string', 'Synopsis'],
                    'year' => ['integer', 'Année de sortie'],
                    'duration' => ['integer', 'Durée en minutes'],
                    'poster' => ['string|null', "URL de l'affiche"],
                    'trailerVideoId' => ['string|null', 'Chemin Bunny de la bande-annonce'],
                    'bunnyVideoId' => ['string|null', 'Chemin Bunny de la vidéo'],
                    'status' => [$adminStatus, 'Nouveau statut'],
                    'studioId' => ['uuid|null', 'Studio propriétaire'],
                ],
            ],
            'DELETE /api/admin/films/{id}' => [
                'tag' => $adminFilms,
                'summary' => 'Supprimer un film',
                'status' => 204,
            ],
            'GET /api/admin/series' => [
                'tag' => $adminSeries,
                'summary' => 'Lister toutes les séries, tous studios confondus',
                'query' => [...$page, ...$limit20, ...$adminFilters],
            ],
            'GET /api/admin/series/{id}' => [
                'tag' => $adminSeries,
                'summary' => "Détail d'une série avec son studio",
            ],
            'PATCH /api/admin/series/{id}' => [
                'tag' => $adminSeries,
                'summary' => 'Modifier une série : métadonnées, statut ou studio',
                'body' => [
                    'title' => ['string', 'Titre (non vide)'],
                    'synopsis' => ['string', 'Synopsis'],
                    'year' => ['integer', 'Année de sortie'],
                    'poster' => ['string|null', "URL de l'affiche"],
                    'trailerVideoId' => ['string|null', 'Chemin Bunny de la bande-annonce'],
                    'status' => [$adminStatus, 'Nouveau statut'],
                    'studioId' => ['uuid|null', 'Studio propriétaire'],
                ],
            ],
            'DELETE /api/admin/series/{id}' => [
                'tag' => $adminSeries,
                'summary' => 'Supprimer une série',
                'status' => 204,
            ],

            // ══ Admin — approbation du 1er contenu d'un studio ═══════════════
            // Chaque action accepte POST et PATCH (le front historique
            // utilise PATCH) : deux opérations pour une même route.
            'GET /api/admin/approvals' => [
                'tag' => $adminApprovals,
                'summary' => "Contenus en attente d'approbation (films et séries)",
                'query' => [...$page, ...$limit20],
            ],
            'POST /api/admin/films/{id}/approve' => [
                'tag' => $adminApprovals,
                'summary' => 'Approuver un film : publié, et studio validé',
                'responses' => [409 => $notPending],
            ],
            'PATCH /api/admin/films/{id}/approve' => [
                'tag' => $adminApprovals,
                'summary' => 'Approuver un film (variante PATCH de la route POST)',
                'responses' => [409 => $notPending],
            ],
            'POST /api/admin/films/{id}/reject' => [
                'tag' => $adminApprovals,
                'summary' => 'Refuser un film : retour en brouillon',
                'responses' => [409 => $notPending],
            ],
            'PATCH /api/admin/films/{id}/reject' => [
                'tag' => $adminApprovals,
                'summary' => 'Refuser un film (variante PATCH de la route POST)',
                'responses' => [409 => $notPending],
            ],
            'POST /api/admin/series/{id}/approve' => [
                'tag' => $adminApprovals,
                'summary' => 'Approuver une série : publiée, et studio validé',
                'responses' => [409 => $notPending],
            ],
            'PATCH /api/admin/series/{id}/approve' => [
                'tag' => $adminApprovals,
                'summary' => 'Approuver une série (variante PATCH de la route POST)',
                'responses' => [409 => $notPending],
            ],
            'POST /api/admin/series/{id}/reject' => [
                'tag' => $adminApprovals,
                'summary' => 'Refuser une série : retour en brouillon',
                'responses' => [409 => $notPending],
            ],
            'PATCH /api/admin/series/{id}/reject' => [
                'tag' => $adminApprovals,
                'summary' => 'Refuser une série (variante PATCH de la route POST)',
                'responses' => [409 => $notPending],
            ],

            // ══ Admin — demandes de retrait ══════════════════════════════════
            'GET /api/admin/withdrawals' => [
                'tag' => $adminWithdrawals,
                'summary' => 'Lister les demandes de retrait',
                'query' => [
                    ...$page,
                    ...$limit20,
                    'status' => ['enum:PENDING,APPROVED,REJECTED', 'Filtrer par statut (défaut PENDING)'],
                ],
            ],
            'GET /api/admin/withdrawals/{id}' => [
                'tag' => $adminWithdrawals,
                'summary' => "Détail d'une demande de retrait",
            ],
            'POST /api/admin/withdrawals/{id}/approve' => [
                'tag' => $adminWithdrawals,
                'summary' => 'Approuver une demande : le contenu passe en WITHDRAWN',
                'body' => $reviewNote,
                'responses' => [409 => 'Demande déjà traitée, ou contenu déjà retiré'],
            ],
            'POST /api/admin/withdrawals/{id}/reject' => [
                'tag' => $adminWithdrawals,
                'summary' => 'Refuser une demande de retrait',
                'body' => $reviewNote,
                'responses' => [409 => 'Demande déjà traitée'],
            ],

            // ══ Admin — studios et stockage Bunny ════════════════════════════
            'GET /api/admin/studios' => [
                'tag' => $adminStudios,
                'summary' => 'Lister tous les studios',
            ],
            'GET /api/admin/bunny/zones' => [
                'tag' => $adminBunny,
                'summary' => 'Lister les Storage Zones configurées et la zone par défaut',
            ],
            'GET /api/admin/bunny/files' => [
                'tag' => $adminBunny,
                'summary' => "Lister les fichiers d'un dossier Bunny Storage",
                'query' => [
                    ...$bunnyListing,
                    'recursive' => ['boolean', 'Parcourir les sous-dossiers (défaut false)'],
                    'type' => ['enum:image,video,audio,document', 'Filtrer par type de fichier'],
                ],
                'responses' => [502 => 'Zone Bunny injoignable'],
            ],
            'GET /api/admin/bunny/images' => [
                'tag' => $adminBunny,
                'summary' => "Lister les images d'un dossier Bunny Storage",
                'query' => [...$bunnyListing, 'recursive' => ['boolean', 'Parcourir les sous-dossiers (défaut true)']],
                'responses' => [502 => 'Zone Bunny injoignable'],
            ],
            'GET /api/admin/bunny/videos' => [
                'tag' => $adminBunny,
                'summary' => "Lister les vidéos d'un dossier Bunny Storage (hors Bunny Stream)",
                'query' => [...$bunnyListing, 'recursive' => ['boolean', 'Parcourir les sous-dossiers (défaut true)']],
                'responses' => [502 => 'Zone Bunny injoignable'],
            ],
        ];
    }
}
