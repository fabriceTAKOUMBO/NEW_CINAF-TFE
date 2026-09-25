<?php

namespace App\Studio\Controller;

use App\Entity\Episode;
use App\Entity\Film;
use App\Entity\Serie;
use App\Entity\User;
use App\Repository\EpisodeRepository;
use App\Repository\FilmRepository;
use App\Repository\SerieRepository;
use App\Service\BunnyZoneRegistry;
use App\Studio\Service\BunnyPathBuilder;
use App\Studio\Service\StudioOwnershipChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Envoi des fichiers d'un studio (affiche, bande-annonce, vidéo) vers Bunny
 * Storage (préfixe `/api/studio/upload`).
 *
 * Accès : ROLE_CREATEUR (attribut `#[IsGranted]` + règle `access_control`
 * `^/api/studio`). Endpoint unique : POST '' (multipart).
 *
 * Le client ne choisit jamais le chemin de destination : il désigne une cible
 * (`targetType` + `targetId`) et un usage (`purpose`), et le serveur construit
 * le chemin via BunnyPathBuilder, sous le dossier du studio propriétaire
 * (« 1 dossier par projet », depuis 2026-05-08). Un fichier ne peut donc
 * jamais atterrir dans le dossier d'un autre studio.
 */
#[Route('/api/studio/upload')]
#[IsGranted('ROLE_CREATEUR')]
class StudioUploadController extends AbstractController
{
    // Types MIME acceptés : vidéo pour purpose=trailer|video, image pour purpose=poster.
    private const VIDEO_MIME = ['video/mp4', 'video/quicktime', 'video/webm'];
    private const IMAGE_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    // Phase H — extensions autorisées (en plus du MIME) pour bloquer un
    // .exe avec un MIME forgé en image/png, etc.
    private const VIDEO_EXT = ['mp4', 'mov', 'webm'];
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp'];

    private const VIDEO_MAX_BYTES = 3 * 1024 * 1024 * 1024; // 3 Go
    private const IMAGE_MAX_BYTES = 10 * 1024 * 1024;       // 10 Mo

    private const TARGET_TYPES = ['film', 'serie', 'episode'];
    private const PURPOSES     = ['poster', 'trailer', 'video'];

    public function __construct(
        private readonly StudioOwnershipChecker $ownershipChecker,
        private readonly BunnyZoneRegistry $zones,
        private readonly BunnyPathBuilder $pathBuilder,
        private readonly FilmRepository $films,
        private readonly SerieRepository $series,
        private readonly EpisodeRepository $episodes,
    ) {
    }

    /**
     * Upload pur (sans persistance d'entité). Le frontend appelle ensuite
     * un PATCH sur l'entité concernée avec le path/URL retourné.
     *
     * Payload multipart :
     *   - file       : binary (obligatoire)
     *   - targetType : "film" | "serie" | "episode" (obligatoire)
     *   - targetId   : UUID de l'entité cible (obligatoire)
     *   - purpose    : "poster" | "trailer" | "video" (obligatoire)
     *
     * Le path Bunny est calculé via BunnyPathBuilder selon la convention
     * "1 dossier par projet" (depuis 2026-05-08).
     *
     * Contrôles, dans l'ordre : studio actif, paramètres de cible, fichier présent
     * et accepté par PHP, taille (10 Mo pour une image, 3 Go pour une vidéo), type
     * MIME puis extension, et enfin propriété de la cible.
     *
     * @return JsonResponse 201 `{url, path, size, mimeType}` ; 400 si un paramètre est
     *                      invalide, si le fichier manque ou si l'upload PHP a échoué,
     *                      ou pour un épisode avec un purpose autre que "video" ;
     *                      403 si l'utilisateur n'a pas de studio actif ou si la cible
     *                      appartient à un autre studio ; 404 si la cible est introuvable ;
     *                      413 si le fichier dépasse la limite ; 415 si le type MIME ou
     *                      l'extension n'est pas autorisé ; 502 si l'envoi vers Bunny échoue
     */
    #[Route('', name: 'studio_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        // Premier contrôle : 403 immédiat si l'utilisateur n'a pas de studio actif.
        $studio = $this->ownershipChecker->getStudioForUser($user);

        // --- Validation des paramètres de cible et purpose ---
        $targetType = (string) $request->request->get('targetType', '');
        $targetId   = (string) $request->request->get('targetId', '');
        $purpose    = (string) $request->request->get('purpose', '');

        if (!\in_array($targetType, self::TARGET_TYPES, true)) {
            return new JsonResponse(
                ['message' => 'Le champ "targetType" doit être "film", "serie" ou "episode".'],
                400,
            );
        }
        if (!\in_array($purpose, self::PURPOSES, true)) {
            return new JsonResponse(
                ['message' => 'Le champ "purpose" doit être "poster", "trailer" ou "video".'],
                400,
            );
        }
        if ($targetId === '' || !Uuid::isValid($targetId)) {
            return new JsonResponse(['message' => 'UUID "targetId" invalide.'], 400);
        }
        $uuid = Uuid::fromString($targetId);

        // Combinaison non supportée : Episode n'a pas de champ poster (dette
        // technique, voir plan). Trailer d'épisode non plus pour l'instant.
        if ($targetType === 'episode' && $purpose !== 'video') {
            return new JsonResponse(
                ['message' => 'Pour un épisode, seul purpose="video" est supporté.'],
                400,
            );
        }

        // --- Validation du fichier ---
        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');
        if ($file === null) {
            return new JsonResponse(['message' => 'Aucun fichier reçu (champ "file").'], 400);
        }

        // PHP a déjà rejeté l'upload pour cause de taille (php.ini
        // upload_max_filesize / post_max_size) → renvoyer 413 directement.
        if (!$file->isValid()) {
            $error = $file->getError();
            if (\in_array($error, [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)) {
                return new JsonResponse(['message' => 'Fichier trop volumineux.'], 413);
            }
            return new JsonResponse(['message' => 'Upload invalide.'], 400);
        }

        // Nature attendue du fichier : une affiche est une image, une
        // bande-annonce ou une vidéo principale est une vidéo.
        $expectedKind = $purpose === 'poster' ? 'image' : 'video';

        // --- Limite de taille (avant MIME pour éviter le coût fileinfo) ---
        // Si la taille annoncée est nulle, on mesure le fichier temporaire sur disque.
        $size = (int) $file->getSize();
        $realPath = $file->getRealPath();
        if (($size <= 0) && $realPath !== false && is_file($realPath)) {
            $size = (int) filesize($realPath);
        }
        $maxBytes = $expectedKind === 'video' ? self::VIDEO_MAX_BYTES : self::IMAGE_MAX_BYTES;
        if ($size > $maxBytes) {
            $maxLabel = $expectedKind === 'video' ? '3 Go' : '10 Mo';
            return new JsonResponse(
                ['message' => sprintf('Fichier trop volumineux (limite : %s).', $maxLabel)],
                413,
            );
        }

        // --- Whitelist MIME selon purpose ---
        // MIME déduit du contenu (fileinfo) ; à défaut, celui annoncé par le
        // client. Ce repli est moins fiable, d'où le second contrôle sur l'extension.
        try {
            $mime = $file->getMimeType() ?? '';
        } catch (\Throwable) {
            $mime = '';
        }
        if ($mime === '') {
            $mime = (string) $file->getClientMimeType();
        }
        $allowedMime = $expectedKind === 'video' ? self::VIDEO_MIME : self::IMAGE_MIME;
        if (!\in_array($mime, $allowedMime, true)) {
            return new JsonResponse(['message' => 'Type de fichier non supporté.'], 415);
        }

        // Phase H — double-check : l'extension du fichier client doit aussi
        // matcher la whitelist.
        $clientExt = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        $allowedExt = $expectedKind === 'video' ? self::VIDEO_EXT : self::IMAGE_EXT;
        if ($clientExt === '' || !\in_array($clientExt, $allowedExt, true)) {
            return new JsonResponse(['message' => 'Extension de fichier non supportée.'], 415);
        }

        // --- Résolution + ownership de l'entité cible ---
        // L'extension du fichier client (en minuscules) devient celle du fichier
        // sur Bunny : un nouvel upload n'écrase le précédent que si l'extension est
        // identique (poster.png n'écrase pas poster.jpg, les deux coexistent).
        $remotePath = null;
        $ext = $clientExt;
        switch ($targetType) {
            case 'film':
                $film = $this->films->find($uuid);
                if ($film === null) {
                    return new JsonResponse(['message' => 'Film introuvable.'], 404);
                }
                $this->ownershipChecker->assertOwnsFilm($film, $user);
                $remotePath = $this->pathBuilder->forFilm($film, $purpose, $ext);
                break;

            case 'serie':
                $serie = $this->series->find($uuid);
                if ($serie === null) {
                    return new JsonResponse(['message' => 'Série introuvable.'], 404);
                }
                $this->ownershipChecker->assertOwnsSerie($serie, $user);
                $remotePath = $this->pathBuilder->forSerie($serie, $purpose, $ext);
                break;

            case 'episode':
                $episode = $this->episodes->find($uuid);
                if ($episode === null) {
                    return new JsonResponse(['message' => 'Épisode introuvable.'], 404);
                }
                // Cascade : episode → season → serie → studio
                $serie = $episode->getSeason()?->getSerie();
                if ($serie === null) {
                    return new JsonResponse(['message' => 'Épisode orphelin (saison/série introuvable).'], 404);
                }
                $this->ownershipChecker->assertOwnsSerie($serie, $user);
                $remotePath = $this->pathBuilder->forEpisode($episode, $purpose, $ext);
                break;
        }

        // --- Upload Bunny ---
        // Aucune entité n'est modifiée ici : le frontend enregistre ensuite
        // `path`/`url` sur la cible par un PATCH. Toute erreur Bunny devient une 502.
        try {
            $bunny = $this->zones->get(); // zone par défaut (cinaftv-movies)
            $url = $bunny->uploadFile($file, $remotePath);
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'Échec upload Bunny.'], 502);
        }

        return new JsonResponse([
            'url'      => $url,
            'path'     => $remotePath,
            'size'     => $size,
            'mimeType' => $mime,
        ], 201);
    }
}
