<?php
namespace App\Admin\Controller;

use App\Service\BunnyStorageService;
use App\Service\BunnyZoneRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Explorateur Bunny Storage du back-office (page `/admin/bunny` du front) :
 * parcourt en lecture seule les fichiers hébergés chez Bunny.net.
 *
 * Préfixe `/api/admin/bunny`, ROLE_ADMIN (`#[IsGranted]` + `access_control`).
 *  - GET /zones   zones de stockage configurées + zone par défaut
 *  - GET /files   contenu d'un dossier (filtre de type facultatif)
 *  - GET /images  images uniquement (récursif par défaut)
 *  - GET /videos  vidéos uniquement (récursif par défaut)
 * Chaque listing accepte `?zone=` : CINAF déclare plusieurs Storage Zones
 * (config/services.yaml) et BunnyZoneRegistry fournit le client de chacune.
 * Aucun endpoint ici n'écrit ni ne supprime de fichier.
 */
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
     *
     * @return JsonResponse 200 `{zones: string[] (ordre alphabétique), default: string}`
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
     *
     * Non récursif par défaut : en mode récursif, chaque sous-dossier coûte un
     * appel HTTP supplémentaire à Bunny.
     *
     * @return JsonResponse 200 `{path, files, directories, zone}` ; 400 zone inconnue ;
     *                      502 API Bunny injoignable ou en erreur
     */
    #[Route('/files', methods: ['GET'])]
    public function listFiles(Request $request): JsonResponse
    {
        return $this->dispatchListing($request, typeFilter: $request->query->get('type'), recursiveDefault: false);
    }

    /**
     * Renvoie la liste filtrée uniquement aux images — utilisé par la page
     * frontend /admin/bunny pour afficher les images stockées.
     *
     * Mêmes paramètres et codes de retour que listFiles(), mais récursif par
     * défaut et `type` imposé à « image ».
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
     *
     * Mêmes paramètres et codes de retour que listFiles() (récursif par défaut) ;
     * en cas de succès, un champ `note` rappelle cette limite.
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
     *
     * Le filtre de type ne s'applique qu'aux fichiers : les sous-dossiers
     * (`directories`) sont renvoyés tels quels. `recursive` accepte les valeurs
     * booléennes usuelles (1/0, true/false, on/off, yes/no) ; absent, il prend
     * la valeur `$recursiveDefault`.
     *
     * @param ?string $typeFilter       type de fichier à conserver (null ou '' = aucun filtre)
     * @param bool    $recursiveDefault valeur de `recursive` si la requête ne le précise pas
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
     * Détermine la zone demandée (`?zone=`, sinon la zone par défaut) et
     * renvoie son nom avec le client BunnyStorageService correspondant.
     *
     * @throws \InvalidArgumentException si la zone n'est pas déclarée (traduite en 400 par l'appelant)
     *
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
