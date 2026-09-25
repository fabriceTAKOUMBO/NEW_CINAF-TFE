<?php
namespace App\Controller;

use App\Entity\Episode;
use App\Entity\Serie;
use App\Repository\EpisodeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Accès aux épisodes de série, sous le préfixe /api/episodes :
 *  - GET /api/episodes/{id}         détail d'un épisode ;
 *  - GET /api/episodes/{id}/stream  infos de lecture Bunny de l'épisode.
 *
 * Les deux endpoints exigent un utilisateur authentifié (401 sinon) et ne
 * servent que les épisodes dont la série parente est PUBLISHED : sinon 404,
 * comme pour un épisode inexistant (un épisode n'a pas de statut propre).
 * Aucun contrôle d'abonnement payant n'est fait ici. Comme dans
 * FilmController, un `{id}` qui n'est pas un UUID provoque une erreur 500.
 */
#[Route('/api/episodes')]
class EpisodeController extends AbstractController
{
    public function __construct(
        private EpisodeRepository $episodes,
        // Identifiant de la Bunny Stream Library injecté via le conteneur Symfony.
        #[Autowire(env: 'BUNNY_STREAM_LIBRARY_ID')]
        private readonly string $bunnyStreamLibraryId,
    ) {}

    /**
     * Détail d'un épisode.
     *
     * @return JsonResponse 200 Episode::toArray() (`{id, number, title, synopsis,
     *                      duration, bunnyVideoId}`) ; 404 `{error}` si l'épisode
     *                      n'existe pas ou si sa série n'est pas PUBLISHED ; 401 sans jeton
     */
    #[Route('/{id}', name: 'episode_get', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function get(string $id): JsonResponse
    {
        $ep = $this->episodes->find($id);
        if (!$ep || !$this->serieIsPublished($ep)) {
            return new JsonResponse(['error' => 'Episode not found'], 404);
        }
        return new JsonResponse($ep->toArray());
    }

    /**
     * Informations de lecture d'un épisode pour le lecteur vidéo : chemin
     * Bunny de la vidéo et identifiant de la Bunny Stream Library.
     *
     * @return JsonResponse 200 `{bunnyVideoId, libraryId}` ; 404 `{error}` si
     *                      l'épisode n'existe pas ou si sa série n'est pas
     *                      PUBLISHED ; 401 sans jeton
     */
    #[Route('/{id}/stream', name: 'episode_stream', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function streamInfo(string $id): JsonResponse
    {
        $ep = $this->episodes->find($id);
        if (!$ep || !$this->serieIsPublished($ep)) {
            return new JsonResponse(['error' => 'Episode not found'], 404);
        }
        return new JsonResponse([
            'bunnyVideoId' => $ep->getBunnyVideoId(),
            'libraryId' => $this->bunnyStreamLibraryId,
        ]);
    }

    /**
     * Un épisode n'est exposé publiquement (même authentifié) que si la série
     * parente est PUBLISHED — sinon 404 pour préserver l'invisibilité des
     * brouillons et des œuvres retirées.
     */
    private function serieIsPublished(Episode $ep): bool
    {
        return $ep->getSeason()->getSerie()->getStatus() === Serie::STATUS_PUBLISHED;
    }
}
