<?php

namespace App\Controller;

use App\Entity\Film;
use App\Entity\Serie;
use App\Entity\Studio;
use App\Entity\StudioSubscription;
use App\Entity\User;
use App\Repository\FilmRepository;
use App\Repository\SerieRepository;
use App\Repository\StudioRepository;
use App\Repository\StudioSubscriptionRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Endpoints publics exposant les studios à la manière d'une « chaîne YouTube ».
 *
 * Un studio est dit « public » dès lors qu'il est actif, validé, et possède
 * au moins un Film ou une Serie en statut `PUBLISHED`. La règle est centralisée
 * dans `StudioRepository::findPublic*()` pour garantir la cohérence entre la
 * liste, la recherche et la fiche détail.
 *
 * Toutes les routes sont en accès libre (PUBLIC_ACCESS dans security.yaml,
 * positionné AVANT la règle `^/api/studio → ROLE_CREATEUR` pour ne pas être
 * masqué par cette dernière).
 */
#[Route('/api/studios')]
class StudioPublicController extends AbstractController
{
    public function __construct(
        private readonly StudioRepository $studioRepo,
        private readonly FilmRepository $filmRepo,
        private readonly SerieRepository $serieRepo,
        private readonly StudioSubscriptionRepository $subscriptionRepo,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Liste paginée des studios publics, triés par nom alphabétique ascendant.
     * Réponse format Hydra (cohérent avec le reste du catalogue public).
     */
    #[Route('', name: 'studios_public_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(1, min(100, $request->query->getInt('itemsPerPage', 30)));

        $studios = $this->studioRepo->findPublicPaginated($page, $limit);
        $total = $this->studioRepo->countPublic();

        // On expose la version publique enrichie des compteurs pour que la
        // carte studio puisse afficher « X films · Y séries · Z abonnés »
        // sans 2e appel.
        $members = array_map(
            fn (Studio $s) => $s->toPublicArray(
                $this->filmRepo->countByStudioAndStatus($s, Film::STATUS_PUBLISHED),
                $this->serieRepo->countByStudioAndStatus($s, Serie::STATUS_PUBLISHED),
                $this->subscriptionRepo->countByStudio($s),
            ),
            $studios,
        );

        return new JsonResponse([
            'hydra:member' => $members,
            'hydra:totalItems' => $total,
        ]);
    }

    /**
     * Recherche un studio public par nom (match partiel insensible à la casse).
     * Retourne un tableau plat (pas Hydra) de 20 résultats max.
     *
     * Min 2 caractères côté serveur : sous ce seuil on renvoie `[]` pour
     * éviter des requêtes inutiles aux multiples résultats.
     */
    #[Route('/search', name: 'studios_public_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        if (mb_strlen($q) < 2) {
            return new JsonResponse([]);
        }

        $studios = $this->studioRepo->searchPublicByName($q, 20);

        $data = array_map(
            fn (Studio $s) => $s->toPublicArray(
                $this->filmRepo->countByStudioAndStatus($s, Film::STATUS_PUBLISHED),
                $this->serieRepo->countByStudioAndStatus($s, Serie::STATUS_PUBLISHED),
                $this->subscriptionRepo->countByStudio($s),
            ),
            $studios,
        );

        return new JsonResponse($data);
    }

    /**
     * Détail d'une fiche studio publique. 404 — et non 403 — si le studio
     * n'existe pas OU s'il ne satisfait pas les critères de visibilité
     * publique. Le filtrage est volontaire pour ne pas leaker l'existence
     * de studios inactifs / non validés / sans contenu publié.
     */
    #[Route('/{slug}', name: 'studios_public_get', methods: ['GET'])]
    public function get(string $slug): JsonResponse
    {
        $studio = $this->studioRepo->findPublicBySlug($slug);
        if ($studio === null) {
            return new JsonResponse(['error' => 'Studio introuvable.'], 404);
        }

        return new JsonResponse($studio->toPublicArray(
            $this->filmRepo->countByStudioAndStatus($studio, Film::STATUS_PUBLISHED),
            $this->serieRepo->countByStudioAndStatus($studio, Serie::STATUS_PUBLISHED),
            $this->subscriptionRepo->countByStudio($studio),
        ));
    }

    /**
     * Liste paginée des films et séries publiés du studio, triés par date
     * de création décroissante (les plus récents d'abord).
     *
     * Le paramètre `?kind=` permet de filtrer (film / serie / all). Quand
     * `kind=all` on assemble les deux listes en PHP puis on trie/coupe ;
     * acceptable au volume MVP (catalogue d'un studio < 200 œuvres).
     */
    #[Route('/{slug}/works', name: 'studios_public_works', methods: ['GET'])]
    public function works(string $slug, Request $request): JsonResponse
    {
        $studio = $this->studioRepo->findPublicBySlug($slug);
        if ($studio === null) {
            return new JsonResponse(['error' => 'Studio introuvable.'], 404);
        }

        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(1, min(100, $request->query->getInt('itemsPerPage', 30)));
        $kind = $request->query->get('kind', 'all');
        if (!in_array($kind, ['film', 'serie', 'all'], true)) {
            $kind = 'all';
        }

        // Étape 1 — récupération « brute » des deux listes selon le filtre.
        // Note : findByStudioPaginated retourne ['data' => Entity[], 'total' => int, ...]
        // d'où l'extraction explicite de la clé 'data' (annotations @var ci-dessous
        // pour aider l'analyseur statique à narrower le type union de l'array shape).
        $rawWorks = [];
        if ($kind === 'film' || $kind === 'all') {
            $filmsPage = $this->filmRepo->findByStudioPaginated($studio, Film::STATUS_PUBLISHED, 1, 500);
            /** @var Film[] $films */
            $films = $filmsPage['data'];
            foreach ($films as $film) {
                $rawWorks[] = $this->mapFilmToWork($film);
            }
        }
        if ($kind === 'serie' || $kind === 'all') {
            $seriesPage = $this->serieRepo->findByStudioPaginated($studio, Serie::STATUS_PUBLISHED, 1, 500);
            /** @var Serie[] $series */
            $series = $seriesPage['data'];
            foreach ($series as $serie) {
                $rawWorks[] = $this->mapSerieToWork($serie);
            }
        }

        // Étape 2 — tri unifié par date de création desc + pagination en mémoire.
        usort($rawWorks, fn (array $a, array $b) => strcmp($b['createdAt'], $a['createdAt']));
        $total = \count($rawWorks);
        $slice = array_slice($rawWorks, ($page - 1) * $limit, $limit);

        return new JsonResponse([
            'hydra:member' => $slice,
            'hydra:totalItems' => $total,
        ]);
    }

    /**
     * Abonne l'utilisateur authentifié au studio (sémantique « follow YouTube »).
     *
     * Idempotent : un POST répété sur le même studio ne crée pas de doublon
     * et retourne 200 (avec le même body) au lieu de 201. La contrainte unique
     * `uniq_user_studio` au niveau DB protège également contre une éventuelle
     * race condition (deux requêtes simultanées) ; on rattrape l'exception
     * Doctrine correspondante pour rester silencieux.
     *
     * 404 si le studio n'existe pas OU n'est pas public (cohérence avec les
     * autres endpoints — on ne leak pas l'existence des studios non publics).
     * 401 géré automatiquement par l'attribut `#[IsGranted]`.
     *
     * Body retourné : `{isSubscribed: true, subscribersCount: int}`.
     */
    #[Route('/{slug}/subscribe', name: 'studios_public_subscribe', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function subscribe(string $slug, Request $request): JsonResponse
    {
        $studio = $this->studioRepo->findPublicBySlug($slug);
        if ($studio === null) {
            return new JsonResponse(['error' => 'Studio introuvable.'], 404);
        }

        /** @var User $user */
        $user = $this->getUser();

        // Si une ligne existe déjà → opération idempotente, 200 sans rien
        // modifier en base.
        if ($this->subscriptionRepo->isSubscribed($user, $studio)) {
            return new JsonResponse([
                'isSubscribed' => true,
                'subscribersCount' => $this->subscriptionRepo->countByStudio($studio),
            ], 200);
        }

        $subscription = new StudioSubscription($user, $studio);
        $this->em->persist($subscription);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            // Race condition : un autre processus a inséré la ligne entre
            // notre `isSubscribed()` et notre `flush()`. On considère l'op
            // comme réussie côté client (idempotence stricte).
            return new JsonResponse([
                'isSubscribed' => true,
                'subscribersCount' => $this->subscriptionRepo->countByStudio($studio),
            ], 200);
        }

        return new JsonResponse([
            'isSubscribed' => true,
            'subscribersCount' => $this->subscriptionRepo->countByStudio($studio),
        ], 201);
    }

    /**
     * Désabonne l'utilisateur authentifié du studio.
     *
     * Idempotent : DELETE sur un studio auquel l'utilisateur n'est pas
     * abonné retourne 204 silencieux (pas 404), exactement comme un DELETE
     * REST canonique sur une ressource déjà supprimée. Le 404 reste réservé
     * au cas où le studio lui-même n'est pas public, pour rester cohérent
     * avec les autres endpoints.
     *
     * Réponse : 204 No Content.
     */
    #[Route('/{slug}/subscribe', name: 'studios_public_unsubscribe', methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function unsubscribe(string $slug, Request $request): JsonResponse
    {
        $studio = $this->studioRepo->findPublicBySlug($slug);
        if ($studio === null) {
            return new JsonResponse(['error' => 'Studio introuvable.'], 404);
        }

        /** @var User $user */
        $user = $this->getUser();

        $subscription = $this->subscriptionRepo->findOneByUserAndStudio($user, $studio);
        if ($subscription !== null) {
            $this->em->remove($subscription);
            $this->em->flush();
        }

        return new JsonResponse(null, 204);
    }

    /**
     * Retourne l'état de l'abonnement de l'utilisateur authentifié pour ce
     * studio. Utilisé par le frontend pour afficher le bouton dans le bon
     * état au chargement de la fiche studio (« S'abonner » vs « Abonné »).
     *
     * Body retourné : `{isSubscribed: bool}`. 404 si studio non public.
     */
    #[Route('/{slug}/subscription', name: 'studios_public_subscription_status', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function subscriptionStatus(string $slug): JsonResponse
    {
        $studio = $this->studioRepo->findPublicBySlug($slug);
        if ($studio === null) {
            return new JsonResponse(['error' => 'Studio introuvable.'], 404);
        }

        /** @var User $user */
        $user = $this->getUser();

        return new JsonResponse([
            'isSubscribed' => $this->subscriptionRepo->isSubscribed($user, $studio),
        ]);
    }

    /**
     * Sérialise un Film vers le DTO « Work » consommé par le frontend.
     */
    private function mapFilmToWork(Film $film): array
    {
        return [
            'id' => $film->getId()->toRfc4122(),
            'slug' => $film->getSlug(),
            'title' => $film->getTitle(),
            'kind' => 'film',
            'poster' => $film->getPoster(),
            'year' => $film->getYear(),
            'createdAt' => $film->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Sérialise une Serie vers le DTO « Work ». Les séries n'ont pas de
     * champ `year` strict (la donnée peut être absente), on renvoie null
     * dans ce cas.
     */
    private function mapSerieToWork(Serie $serie): array
    {
        return [
            'id' => $serie->getId()->toRfc4122(),
            'slug' => $serie->getSlug(),
            'title' => $serie->getTitle(),
            'kind' => 'serie',
            'poster' => $serie->getPoster(),
            'year' => $serie->getYear(),
            'createdAt' => $serie->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
