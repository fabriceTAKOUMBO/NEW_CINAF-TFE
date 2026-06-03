<?php
namespace App\Admin\Controller;

use App\Service\BunnyStorageService;
use App\Service\BunnyZoneRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/bunny')]
#[IsGranted('ROLE_ADMIN')]
class AdminBunnyController extends AbstractController
{
    public function __construct(
        private readonly BunnyZoneRegistry $zones,
    ) {}

    /**
     * Liste les Storage Zones disponibles + nom de la zone par défaut.
     * Sert au frontend à peupler le sélecteur de zone.
     */
    #[Route('/zones', methods: ['GET'])]
    public function listZones(): JsonResponse
    {
        return $this->json([
            'zones'   => $this->zones->getZoneNames(),
            'default' => $this->zones->getDefaultZoneName(),
        ]);
    }

    /**
     * Liste les fichiers stockés sur une Storage Zone Bunny.
     * Query params :
     *   - zone (optional) : nom de la zone (défaut = zone par défaut configurée)
     *   - path (optional) : dossier à lister (ex: "posters/films")
     *   - recursive (bool) : listage récursif
     *   - type (optional) : filtre par type ("image" | "video" | "audio" | "document")
     */
    #[Route('/files', methods: ['GET'])]
    public function listFiles(Request $request): JsonResponse
    {
        return $this->dispatchListing($request, typeFilter: $request->query->get('type'), recursiveDefault: false);
    }

    /**
     * Renvoie la liste filtrée uniquement aux images — utilisé par la page
     * frontend /admin/bunny pour afficher les images stockées.
     */
    #[Route('/images', methods: ['GET'])]
    public function listImages(Request $request): JsonResponse
    {
        return $this->dispatchListing($request, typeFilter: 'image', recursiveDefault: true);
    }

    /**
     * Renvoie la liste filtrée uniquement aux vidéos stockées sur Bunny Storage.
     * NB : les vidéos servies par Bunny **Stream** (HLS adaptatif, iframe player)
     * ne sont PAS retournées ici — elles relèvent d'une API distincte
     * (video.bunnycdn.com), à implémenter quand les credentials Stream seront fournis.
     */
    #[Route('/videos', methods: ['GET'])]
    public function listVideos(Request $request): JsonResponse
    {
        $response = $this->dispatchListing($request, typeFilter: 'video', recursiveDefault: true);
        if ($response->getStatusCode() === 200) {
            $payload = json_decode($response->getContent() ?: '{}', true);
            $payload['note'] = 'Liste limitée aux fichiers vidéo présents sur Bunny Storage. Bunny Stream n\'est pas interrogé ici (credentials Stream non configurés).';
            return $this->json($payload);
        }
        return $response;
    }

    /**
     * Helper : résout la zone, exécute le listing, applique le filtre type.
     */
    private function dispatchListing(Request $request, ?string $typeFilter, bool $recursiveDefault): JsonResponse
    {
        try {
            [$zoneName, $service] = $this->resolveZone($request);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['message' => $e->getMessage()], 400);
        }

        $path = (string) $request->query->get('path', '');
        $recursive = filter_var(
            $request->query->get('recursive', $recursiveDefault),
            FILTER_VALIDATE_BOOLEAN,
        );

        try {
            $result = $service->listContents($path, $recursive);
        } catch (\RuntimeException $e) {
            return $this->json([
                'message' => "Impossible de lister la zone Bunny '$zoneName'.",
                'detail'  => $e->getMessage(),
            ], 502);
        }

        if ($typeFilter !== null && $typeFilter !== '') {
            $result['files'] = array_values(array_filter(
                $result['files'],
                static fn(array $f) => $f['type'] === $typeFilter,
            ));
        }

        $result['zone'] = $zoneName;
        return $this->json($result);
    }

    /**
     * @return array{0:string,1:BunnyStorageService}
     */
    private function resolveZone(Request $request): array
    {
        $requested = $request->query->get('zone');
        $name = ($requested === null || $requested === '')
            ? $this->zones->getDefaultZoneName()
            : (string) $requested;

        return [$name, $this->zones->get($name)];
    }
}
