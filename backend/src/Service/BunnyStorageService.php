<?php
namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Client Bunny Storage basé sur l'API REST NATIVE (pas S3).
 *
 * Pourquoi pas S3 ? Une Storage Zone Bunny n'expose l'API S3-compatible
 * que si l'option est explicitement activée dans le dashboard. L'API native
 * (header `AccessKey`) fonctionne par défaut avec le mot de passe de la zone.
 *
 * Une instance = UNE Storage Zone (un « bucket » avec sa propre AccessKey).
 * Le service n'est donc pas autowiré (voir `config/services.yaml`) : il est
 * créé à la demande par `BunnyZoneRegistry::get($zone)`.
 *
 * Utilisé par : l'upload studio (`StudioUploadController`), le catalogue
 * « Découvrir » en source bunny (`BunnyCatalogueService`), les URL HLS de
 * `CatalogueDiscoverController`, l'import des affiches
 * (`ImportCataloguePostersCommand`) et les outils de diagnostic
 * (`AdminBunnyController`, commande `app:bunny:list`).
 *
 * Documentation : https://docs.bunny.net/reference/storage-api
 */
class BunnyStorageService
{
    // Extensions reconnues par detectType() pour typer chaque fichier listé.
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'avif'];
    private const VIDEO_EXT = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v', 'ts', 'm3u8'];
    private const AUDIO_EXT = ['mp3', 'wav', 'ogg', 'aac', 'flac', 'm4a'];
    private const DOC_EXT   = ['pdf', 'txt', 'md', 'doc', 'docx', 'xls', 'xlsx'];

    private readonly Client $http;
    private readonly string $baseUri;

    /**
     * Prépare un client Guzzle pré-configuré pour la zone : URL de base,
     * header d'authentification `AccessKey` et vérification TLS.
     *
     * @param string $endpoint        Hôte de l'API Storage. Il dépend de la région de la zone
     *                                (ex. `https://se.storage.bunnycdn.com` pour une zone à Stockholm).
     * @param string $bunnyCdnBaseUrl URL de la pull zone publique (ex. `https://cinaftv-movies.b-cdn.net`),
     *                                utilisée par getPublicUrl() ; chaîne vide = chemins relatifs.
     * @param string $caBundle        Chemin du bundle de certificats CA (`config/certs/cacert.pem`) ;
     *                                chaîne vide = magasin de certificats du système.
     */
    public function __construct(
        private readonly string $endpoint,       // ex: https://storage.bunnycdn.com
        private readonly string $storageZone,    // ex: backupcinaf
        private readonly string $accessKey,      // mot de passe de la storage zone
        private readonly string $bunnyCdnBaseUrl = '',
        private readonly string $caBundle = '',
    ) {
        // Le slash final est indispensable : Guzzle résout les chemins relatifs
        // selon la RFC 3986, et sans lui le segment « nom de zone » serait remplacé
        // au lieu d'être complété.
        $this->baseUri = rtrim($endpoint, '/') . '/' . trim($storageZone, '/') . '/';
        $this->http = new Client([
            'base_uri'        => $this->baseUri,
            'connect_timeout' => 10,   // 10s pour établir la connexion TCP/TLS
            'timeout'         => 0,    // illimité pour le transfert (gros fichiers ≥ 1 Go)
            // Sous Windows, PHP n'a pas de magasin de certificats système : sans le
            // bundle CA Mozilla, toute requête HTTPS vers Bunny échoue (erreur cURL 60).
            'verify'          => $caBundle !== '' ? $caBundle : true,
            'headers'         => [
                'AccessKey' => $accessKey,
                'Accept'    => 'application/json',
            ],
        ]);
    }

    /**
     * Liste le contenu d'un dossier du bucket Bunny Storage.
     *
     * Les dossiers et les fichiers sont renvoyés séparément, triés par nom.
     * En mode récursif, chaque sous-dossier déclenche un appel HTTP
     * supplémentaire : à réserver aux petites arborescences ou au diagnostic.
     *
     * @param string $path      Chemin du dossier relatif à la racine de la zone ('' = racine).
     * @param bool   $recursive true pour aplatir aussi le contenu de tous les sous-dossiers.
     *
     * @throws \RuntimeException si l'API Bunny est injoignable, répond en erreur ou renvoie un JSON invalide.
     *
     * @return array{
     *   path:string,
     *   files:list<array{path:string,name:string,size:int,lastModified:?int,url:string,type:string,extension:string,contentType:?string}>,
     *   directories:list<array{path:string,name:string}>
     * }
     */
    public function listContents(string $path = '', bool $recursive = false): array
    {
        $path = trim($path, '/');
        // L'API native liste un dossier quand le chemin se termine par « / »
        // (sans lui, le GET viserait un fichier). '' = racine de la zone.
        $relative = $path === '' ? '' : $path . '/';

        try {
            $response = $this->http->get($relative);
            $items = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (GuzzleException|\JsonException $e) {
            throw new \RuntimeException(
                'Bunny Storage API error: ' . $e->getMessage(),
                previous: $e,
            );
        }

        $files = [];
        $directories = [];

        // Chaque entrée Bunny porte ObjectName, IsDirectory, Length (octets),
        // LastChanged (date) et ContentType : on la normalise en tableau PHP
        // avec un chemin complet relatif à la racine de la zone.
        foreach ((array) $items as $item) {
            $name = (string) ($item['ObjectName'] ?? '');
            if ($name === '') {
                continue;
            }
            $itemPath = ($path !== '' ? $path . '/' : '') . $name;

            if (!empty($item['IsDirectory'])) {
                $directories[] = ['path' => $itemPath, 'name' => $name];

                if ($recursive) {
                    $sub = $this->listContents($itemPath, true);
                    $files       = array_merge($files, $sub['files']);
                    $directories = array_merge($directories, $sub['directories']);
                }
                continue;
            }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $files[] = [
                'path'         => $itemPath,
                'name'         => $name,
                'size'         => (int) ($item['Length'] ?? 0),
                'lastModified' => isset($item['LastChanged']) ? strtotime((string) $item['LastChanged']) : null,
                'url'          => $this->getPublicUrl($itemPath),
                'extension'    => $ext,
                'type'         => $this->detectType($ext),
                'contentType'  => $item['ContentType'] ?? null,
            ];
        }

        usort($directories, fn($a, $b) => strcmp($a['name'], $b['name']));
        usort($files, fn($a, $b) => strcmp($a['name'], $b['name']));

        return ['path' => $path, 'files' => $files, 'directories' => $directories];
    }

    /**
     * Envoie un fichier reçu en multipart (upload studio) vers la zone.
     *
     * Le fichier temporaire PHP est lu en flux puis transmis à writeStream() :
     * un fichier déjà présent au même chemin est écrasé (convention CINAF
     * « 1 dossier par projet », pas d'historique).
     *
     * @param string $remotePath Chemin cible dans la zone (ex. `studios/{studio}/{film}/video.mp4`).
     *
     * @throws \RuntimeException si le fichier temporaire est illisible ou si l'upload échoue.
     *
     * @return string URL publique (pull zone) du fichier envoyé.
     */
    public function uploadFile(UploadedFile $file, string $remotePath): string
    {
        // Streaming pour ne pas charger le fichier en RAM (vital pour ≥ 1 Go).
        $stream = @fopen($file->getRealPath(), 'rb');
        if ($stream === false) {
            throw new \RuntimeException("Unable to open uploaded file: {$file->getClientOriginalName()}");
        }

        try {
            return $this->writeStream(
                $remotePath,
                $stream,
                $file->getMimeType() ?? 'application/octet-stream',
                $file->getSize() ?: null,
            );
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Upload streamé d'un resource (fopen) — Guzzle envoie en chunks, RAM constante.
     *
     * Côté Bunny, un PUT crée le fichier ou remplace celui qui existe déjà au
     * même chemin (les dossiers intermédiaires n'ont pas à être créés).
     *
     * @param resource $stream
     *
     * @throws \RuntimeException si Bunny refuse l'upload ou si le réseau échoue.
     *
     * @return string URL publique (pull zone) du fichier écrit.
     */
    public function writeStream(string $remotePath, $stream, ?string $mimeType = null, ?int $contentLength = null): string
    {
        $remotePath = ltrim($remotePath, '/');
        $headers = ['Content-Type' => $mimeType ?? 'application/octet-stream'];
        if ($contentLength !== null && $contentLength > 0) {
            $headers['Content-Length'] = (string) $contentLength;
        }

        try {
            $this->http->put($remotePath, [
                'headers' => $headers,
                'body'    => $stream,
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Bunny Storage upload error: ' . $e->getMessage(), previous: $e);
        }

        return $this->getPublicUrl($remotePath);
    }

    /**
     * Écrit un contenu déjà en mémoire (chaîne) à un chemin de la zone.
     * Variante de writeStream() réservée aux petits fichiers ; écrase un
     * fichier existant au même chemin.
     *
     * @throws \RuntimeException si Bunny refuse l'écriture ou si le réseau échoue.
     *
     * @return string URL publique (pull zone) du fichier écrit.
     */
    public function writeContents(string $remotePath, string $contents, ?string $mimeType = null): string
    {
        $remotePath = ltrim($remotePath, '/');

        try {
            $this->http->put($remotePath, [
                'headers' => [
                    'Content-Type' => $mimeType ?? 'application/octet-stream',
                ],
                'body' => $contents,
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Bunny Storage upload error: ' . $e->getMessage(), previous: $e);
        }

        return $this->getPublicUrl($remotePath);
    }

    /**
     * Supprime un fichier de la zone.
     *
     * Idempotent : un fichier déjà absent (réponse 404) n'est pas une erreur.
     *
     * @throws \RuntimeException pour toute autre erreur (réseau, 401, 5xx…).
     */
    public function deleteFile(string $remotePath): void
    {
        try {
            $this->http->delete(ltrim($remotePath, '/'));
        } catch (GuzzleException $e) {
            // Seules les exceptions « réponse HTTP reçue » exposent getResponse() :
            // un 404 signifie que le fichier n'existe déjà plus, la suppression
            // est donc considérée comme réussie.
            if (method_exists($e, 'getResponse') && $e->getResponse() && $e->getResponse()->getStatusCode() === 404) {
                return;
            }
            throw new \RuntimeException('Bunny Storage delete error: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * Teste l'existence d'un fichier par une requête HEAD (sans télécharger son contenu).
     *
     * @throws \RuntimeException si l'erreur n'est pas un 404 (réseau, timeout, 5xx).
     */
    public function fileExists(string $remotePath): bool
    {
        try {
            $this->http->request('HEAD', ltrim($remotePath, '/'));
            return true;
        } catch (GuzzleException $e) {
            // Un 404 signifie réellement « le fichier n'existe pas ».
            if (method_exists($e, 'getResponse') && $e->getResponse() && $e->getResponse()->getStatusCode() === 404) {
                return false;
            }
            // Toute autre erreur (réseau, timeout, 5xx) ne doit PAS être confondue
            // avec une absence de fichier : on la propage, sinon une panne réseau
            // déclencherait des décisions d'idempotence erronées (réimport/écrasement).
            throw new \RuntimeException('Bunny Storage HEAD error: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * Construit l'URL publique d'un fichier, servie par la pull zone (CDN)
     * associée à la zone de stockage — et non par l'API Storage, qui exige
     * l'AccessKey.
     *
     * Exemple : `12_CAS/CAS_1/CAS1_E01/master.m3u8` →
     * `https://cinaftv-movies.b-cdn.net/12_CAS/CAS_1/CAS1_E01/master.m3u8`.
     *
     * @return string URL absolue, ou le chemin relatif seul si aucune pull zone n'est configurée.
     */
    public function getPublicUrl(string $remotePath): string
    {
        $base = rtrim($this->bunnyCdnBaseUrl, '/');
        $path = ltrim($remotePath, '/');
        return $base ? "$base/$path" : $path;
    }

    /**
     * Classe un fichier d'après son extension (image, video, audio, document
     * ou other) ; la catégorie est exposée dans le champ `type` du listing.
     */
    private function detectType(string $ext): string
    {
        return match (true) {
            in_array($ext, self::IMAGE_EXT, true) => 'image',
            in_array($ext, self::VIDEO_EXT, true) => 'video',
            in_array($ext, self::AUDIO_EXT, true) => 'audio',
            in_array($ext, self::DOC_EXT, true)   => 'document',
            default => 'other',
        };
    }
}
