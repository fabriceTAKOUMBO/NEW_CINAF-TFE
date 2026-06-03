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

#[Route('/api/episodes')]
class EpisodeController extends AbstractController
{
    public function __construct(
        private EpisodeRepository $episodes,
        // Identifiant de la Bunny Stream Library injecté via le conteneur Symfony.
        #[Autowire(env: 'BUNNY_STREAM_LIBRARY_ID')]
        private readonly string $bunnyStreamLibraryId,
    ) {}

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
