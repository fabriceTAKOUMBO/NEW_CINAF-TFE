<?php

namespace App\Studio\Controller;

use App\Entity\Film;
use App\Entity\User;
use App\Studio\Service\ContentLifecycleService;
use App\Studio\Service\StudioOwnershipChecker;
use App\Repository\FilmRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Gestion des films d'un studio depuis l'espace studio (préfixe `/api/studio/films`).
 *
 * Accès : ROLE_CREATEUR, exigé deux fois (attribut `#[IsGranted]` ci-dessous et
 * règle `access_control` `^/api/studio` de security.yaml). Un utilisateur ne
 * manipule que les films du studio actif qu'il possède : la propriété est
 * vérifiée par StudioOwnershipChecker, qui lève une 403 dans le cas contraire.
 *
 * Endpoints :
 *  - GET    ''              liste paginée des films du studio (filtre `status` optionnel)
 *  - GET    /{id}           détail d'un film
 *  - POST   ''              création d'un film, toujours en DRAFT
 *  - PATCH  /{id}           modification partielle (refusée en PENDING_APPROVAL)
 *  - DELETE /{id}           suppression, possible uniquement en DRAFT
 *  - POST   /{id}/publish   publication (PUBLISHED, ou PENDING_APPROVAL si le studio n'est pas validé)
 *  - POST   /{id}/withdraw  demande de retrait adressée à l'admin (WithdrawalRequest)
 *
 * Les changements de statut passent par ContentLifecycleService, jamais en
 * ligne dans ce contrôleur.
 */
#[Route('/api/studio/films')]
#[IsGranted('ROLE_CREATEUR')]
class StudioFilmController extends AbstractController
{
    public function __construct(
        private readonly FilmRepository $filmRepo,
        private readonly StudioOwnershipChecker $ownershipChecker,
        private readonly ContentLifecycleService $lifecycle,
        private readonly EntityManagerInterface $em,
        private readonly SluggerInterface $slugger,
    ) {
    }

    /**
     * Liste paginée des films du studio de l'utilisateur, du plus récent au plus ancien.
     *
     * Paramètres de requête : `page` (défaut 1), `limit` (défaut 30, borné entre 1 et 100)
     * et `status` optionnel (DRAFT, PUBLISHED, WITHDRAWN ou PENDING_APPROVAL).
     *
     * @return JsonResponse 200 `{data, total, page, limit}`, chaque film au format
     *                      détaillé `Film::toArray(true)` ; 400 si `status` est inconnu ;
     *                      403 si l'utilisateur n'a pas de studio actif
     */
    #[Route('', name: 'studio_films_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        // Le studio vient toujours de l'utilisateur connecté, jamais d'un paramètre :
        // impossible de lister les films d'un autre studio.
        $studio = $this->ownershipChecker->getStudioForUser($user);

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 30)));
        $status = $request->query->get('status');

        $allowedStatuses = [
            Film::STATUS_DRAFT,
            Film::STATUS_PUBLISHED,
            Film::STATUS_WITHDRAWN,
            Film::STATUS_PENDING_APPROVAL,
        ];
        if ($status !== null && $status !== '' && !\in_array($status, $allowedStatuses, true)) {
            return new JsonResponse(['message' => 'Statut invalide.'], 400);
        }

        $result = $this->filmRepo->findByStudioPaginated($studio, $status, $page, $limit);

        return new JsonResponse([
            'data' => array_map(fn(Film $f) => $f->toArray(true), $result['data']),
            'total' => $result['total'],
            'page' => $result['page'],
            'limit' => $result['limit'],
        ]);
    }

    /**
     * Détail d'un film du studio.
     *
     * @return JsonResponse 200 film (`Film::toArray(true)`) ; 400 si `id` n'est pas un UUID ;
     *                      404 si le film n'existe pas ; 403 s'il n'appartient pas
     *                      au studio actif de l'utilisateur
     */
    #[Route('/{id}', name: 'studio_films_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $film = $this->resolveFilmForUser($id, $user);
        if ($film instanceof JsonResponse) {
            return $film;
        }

        return new JsonResponse($film->toArray(true));
    }

    /**
     * Crée un film en DRAFT, rattaché au studio de l'utilisateur.
     *
     * Corps JSON : `title`, `synopsis`, `year` et `duration` (en minutes) obligatoires ;
     * `slug` et `bunnyVideoId` facultatifs. Le film reste invisible du public
     * tant qu'il n'est pas publié.
     *
     * @return JsonResponse 201 film créé (`Film::toArray(true)`) ; 400 si le JSON est
     *                      invalide ou si un champ obligatoire manque ; 403 si
     *                      l'utilisateur n'a pas de studio actif ou si `bunnyVideoId`
     *                      pointe hors de `studios/{slug-du-studio}/`
     */
    #[Route('', name: 'studio_films_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $studio = $this->ownershipChecker->getStudioForUser($user);

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['message' => 'Corps JSON invalide.'], 400);
        }

        foreach (['title', 'synopsis', 'year', 'duration'] as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                return new JsonResponse(['message' => sprintf('Le champ "%s" est requis.', $field)], 400);
            }
        }

        // Phase H — si un bunnyVideoId est fourni à la création, il doit
        // appartenir au studio (path `studios/{slug}/...`). Un producteur
        // ne peut pas créer un contenu pointant vers le path d'un autre
        // studio.
        if (isset($data['bunnyVideoId']) && $data['bunnyVideoId'] !== '' && $data['bunnyVideoId'] !== null) {
            $this->ownershipChecker->assertBunnyPathOwnership((string) $data['bunnyVideoId'], $studio);
        }

        $title = (string) $data['title'];
        // Slug fourni par le client, sinon dérivé du titre avec un suffixe
        // aléatoire de 6 caractères hexadécimaux : deux films homonymes
        // obtiennent ainsi des slugs distincts (le slug est unique en base et
        // sert de nom de dossier Bunny du projet).
        $slug = isset($data['slug']) && $data['slug'] !== ''
            ? (string) $data['slug']
            : $this->slugger->slug($title)->lower() . '-' . substr(bin2hex(random_bytes(4)), 0, 6);

        $film = new Film();
        $film->setTitle($title);
        $film->setSlug($slug);
        $film->setSynopsis((string) $data['synopsis']);
        $film->setYear((int) $data['year']);
        $film->setDuration((int) $data['duration']);
        if (isset($data['bunnyVideoId']) && $data['bunnyVideoId'] !== '' && $data['bunnyVideoId'] !== null) {
            $film->setBunnyVideoId((string) $data['bunnyVideoId']);
        }
        $film->setStudio($studio);
        $film->setStatus(Film::STATUS_DRAFT);

        $this->em->persist($film);
        $this->em->flush();

        return new JsonResponse($film->toArray(true), 201);
    }

    /**
     * Modifie partiellement un film du studio : seuls les champs présents dans
     * le corps JSON sont appliqués, les clés inconnues sont ignorées.
     *
     * Champs acceptés : `title`, `synopsis`, `year`, `duration` (une valeur null
     * y est ignorée) ; `poster` et `trailerVideoId` (null efface la valeur) ;
     * `bunnyVideoId`, soumis aux règles de propriété du chemin Bunny (Phase H).
     *
     * @return JsonResponse 200 film modifié ; 400 si `id` n'est pas un UUID ou si le
     *                      JSON est invalide ; 403 si le film n'appartient pas au studio,
     *                      si son chemin Bunny actuel provient de l'import ou si le
     *                      nouveau chemin sort de `studios/{slug-du-studio}/` ;
     *                      404 si le film n'existe pas ; 409 s'il est en PENDING_APPROVAL
     */
    #[Route('/{id}', name: 'studio_films_patch', methods: ['PATCH'])]
    public function patch(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $film = $this->resolveFilmForUser($id, $user);
        if ($film instanceof JsonResponse) {
            return $film;
        }

        // Un contenu en attente d'approbation est gelé côté studio :
        // l'admin doit d'abord refuser pour repasser en DRAFT.
        // Seul cet état est gelé : un film DRAFT, PUBLISHED ou WITHDRAWN reste modifiable.
        if ($film->getStatus() === Film::STATUS_PENDING_APPROVAL) {
            return new JsonResponse(
                ['message' => "Contenu en attente d'approbation : modification impossible."],
                409,
            );
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['message' => 'Corps JSON invalide.'], 400);
        }

        if (isset($data['title'])) { $film->setTitle((string) $data['title']); }
        if (isset($data['synopsis'])) { $film->setSynopsis((string) $data['synopsis']); }
        if (isset($data['year'])) { $film->setYear((int) $data['year']); }
        if (isset($data['duration'])) { $film->setDuration((int) $data['duration']); }
        if (\array_key_exists('poster', $data)) { $film->setPoster($data['poster'] === null ? null : (string) $data['poster']); }
        if (\array_key_exists('trailerVideoId', $data)) { $film->setTrailerVideoId($data['trailerVideoId'] === null ? null : (string) $data['trailerVideoId']); }

        // Phase H — restriction sur la modification de bunnyVideoId :
        // 1) Si l'entité a déjà un path "importé" (ne commence pas par
        //    `studios/`, ex: `12_CAS/CAS_1/CAS1_E01` issu de Phase F), le
        //    producteur ne peut pas le modifier (l'admin peut via
        //    /api/admin/films/{id} qui n'a pas cette contrainte).
        // 2) Sinon, le nouveau path doit pointer vers `studios/{slug}/...`.
        if (\array_key_exists('bunnyVideoId', $data)) {
            $newValue = $data['bunnyVideoId'];
            $current = $film->getBunnyVideoId();
            if ($this->ownershipChecker->isImportedBunnyPath($current)) {
                return new JsonResponse([
                    'message' => "Le chemin Bunny d'un contenu importé ne peut être modifié.",
                ], 403);
            }
            if ($newValue !== null && $newValue !== '') {
                $studio = $this->ownershipChecker->getStudioForUser($user);
                $this->ownershipChecker->assertBunnyPathOwnership((string) $newValue, $studio);
            }
            $film->setBunnyVideoId($newValue === null ? null : (string) $newValue);
        }

        $this->em->flush();

        return new JsonResponse($film->toArray(true));
    }

    /**
     * Supprime définitivement un film, uniquement s'il est encore en DRAFT.
     * Les fichiers déjà envoyés sur Bunny ne sont pas supprimés.
     *
     * Dans tout autre statut (PENDING_APPROVAL, PUBLISHED, WITHDRAWN), la réponse
     * est 409 : un contenu publié ne disparaît du catalogue que par une demande
     * de retrait validée par un administrateur.
     *
     * @return Response 204 sans contenu ; 400 si `id` n'est pas un UUID ; 403 si le film
     *                  n'appartient pas au studio ; 404 s'il n'existe pas ;
     *                  409 s'il n'est pas en DRAFT
     */
    #[Route('/{id}', name: 'studio_films_delete', methods: ['DELETE'])]
    public function delete(string $id): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $film = $this->resolveFilmForUser($id, $user);
        if ($film instanceof JsonResponse) {
            return $film;
        }

        if ($film->getStatus() !== Film::STATUS_DRAFT) {
            return new JsonResponse([
                'message' => 'Un film publié ne peut être supprimé directement, faites une demande de retrait.',
            ], 409);
        }

        $this->em->remove($film);
        $this->em->flush();

        return new Response('', 204);
    }

    /**
     * Publie un film en DRAFT (transition déléguée à ContentLifecycleService::publishFilm()).
     *
     * Le film passe en PUBLISHED si le studio est déjà validé ; sinon (premier
     * contenu d'un studio créé en self-service) il passe en PENDING_APPROVAL et
     * attend l'approbation d'un administrateur.
     *
     * @return JsonResponse 200 film avec son nouveau statut ; 400 si le film n'est pas
     *                      en DRAFT ou si `id` n'est pas un UUID ; 403 si le film
     *                      n'appartient pas au studio ; 404 s'il n'existe pas
     */
    #[Route('/{id}/publish', name: 'studio_films_publish', methods: ['POST'])]
    public function publish(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $film = $this->resolveFilmForUser($id, $user);
        if ($film instanceof JsonResponse) {
            return $film;
        }

        $this->lifecycle->publishFilm($film);

        return new JsonResponse($film->toArray(true));
    }

    /**
     * Demande le retrait d'un film : crée une WithdrawalRequest en PENDING que
     * l'admin approuvera (le film passe alors en WITHDRAWN) ou rejettera.
     *
     * Corps JSON : `reason` obligatoire (non vide). Le statut du film n'est pas
     * contrôlé ici ; seule l'unicité d'une demande PENDING par contenu est imposée
     * (ContentLifecycleService::requestWithdrawal()).
     *
     * @return JsonResponse 201 demande créée (`WithdrawalRequest::toArray()`) ; 400 si
     *                      `reason` manque ou si `id` n'est pas un UUID ; 403 si le film
     *                      n'appartient pas au studio ; 404 s'il n'existe pas ;
     *                      409 si une demande de retrait est déjà en attente
     */
    #[Route('/{id}/withdraw', name: 'studio_films_withdraw', methods: ['POST'])]
    public function withdraw(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $film = $this->resolveFilmForUser($id, $user);
        if ($film instanceof JsonResponse) {
            return $film;
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || !isset($data['reason']) || trim((string) $data['reason']) === '') {
            return new JsonResponse(['message' => 'Le champ "reason" est requis.'], 400);
        }

        $studio = $this->ownershipChecker->getStudioForUser($user);
        $withdrawal = $this->lifecycle->requestWithdrawal(
            'film',
            $film->getId(),
            $studio,
            $user,
            (string) $data['reason'],
        );

        return new JsonResponse($withdrawal->toArray(), 201);
    }

    /**
     * Résout un film par UUID et vérifie l'ownership. Retourne soit Film,
     * soit une JsonResponse d'erreur (400 invalid uuid, 404 not found).
     * Le cas « non-propriétaire » n'est pas renvoyé mais levé : assertOwnsFilm()
     * lance une AccessDeniedHttpException, que Symfony convertit en 403.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
     */
    private function resolveFilmForUser(string $id, User $user): Film|JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['message' => 'Identifiant invalide.'], 400);
        }

        $film = $this->filmRepo->find($uuid);
        if ($film === null) {
            return new JsonResponse(['message' => 'Film introuvable.'], 404);
        }

        $this->ownershipChecker->assertOwnsFilm($film, $user);

        return $film;
    }
}
