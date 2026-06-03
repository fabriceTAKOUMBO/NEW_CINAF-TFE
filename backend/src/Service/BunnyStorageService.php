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
 * Documentation : https://docs.bunny.net/reference/storage-api
 */
class BunnyStorageService
{
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'avif'];
    private const VIDEO_EXT = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v', 'ts', 'm3u8'];
    private const AUDIO_EXT = ['mp3', 'wav', 'ogg', 'aac', 'flac', 'm4a'];
    private const DOC_EXT   = ['pdf', 'txt', 'md', 'doc', 'docx', 'xls', 'xlsx'];

    private readonly Client $http;
    private readonly string $baseUri;

    public function __construct(
        private readonly string $endpoint,       // ex: https://storage.bunnycdn.com
        private readonly string $storageZone,    // ex: backupcinaf
        private readonly string $accessKey,      // mot de passe de la storage zone
        private readonly string $bunnyCdnBaseUrl = '',
        private readonly string $caBundle = '',
    ) {
        $this->baseUri = rtrim($endpoint, '/') . '/' . trim($storageZone, '/') . '/';
        $this->http = new Client([
            'base_uri'        => $this->baseUri,
            'connect_timeout' => 10,   // 10s pour établir la connexion TCP/TLS
            'timeout'         => 0,    // illimité pour le transfert (gros fichiers ≥ 1 Go)
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
     * @return array{
     *   path:string,
     *   files:list<array{path:string,name:string,size:int,lastModified:?int,url:string,type:string,extension:string,contentType:?string}>,
     *   directories:list<array{path:string,name:string}>
     * }
     */
    public function listContents(string $path = '', bool $recursive = false): array
    {
        $path = trim($path, '/');
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
     * @param resource $stream
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

    public function deleteFile(string $remotePath): void
    {
        try {
            $this->http->delete(ltrim($remotePath, '/'));
        } catch (GuzzleException $e) {
            if (method_exists($e, 'getResponse') && $e->getResponse() && $e->getResponse()->getStatusCode() === 404) {
                return;
            }
            throw new \RuntimeException('Bunny Storage delete error: ' . $e->getMessage(), previous: $e);
        }
    }

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

    public function getPublicUrl(string $remotePath): string
    {
        $base = rtrim($this->bunnyCdnBaseUrl, '/');
        $path = ltrim($remotePath, '/');
        return $base ? "$base/$path" : $path;
    }

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
