<?php

declare(strict_types=1);

namespace App\Studio\Service;

use App\Entity\Episode;
use App\Entity\Film;
use App\Entity\Serie;

/**
 * Centralise la construction du path Bunny pour un upload studio.
 *
 * Convention (depuis 2026-05-08) — arborescence par projet :
 *   studios/{studio.slug}/{film.slug}/{purpose}.{ext}
 *   studios/{studio.slug}/{serie.slug}/{purpose}.{ext}
 *   studios/{studio.slug}/{serie.slug}/saison-{N}/episode-{NN}/{purpose}.{ext}
 *
 * Nommage déterministe : un nouvel upload du même purpose écrase le précédent
 * sur Bunny. {purpose} ∈ {poster, trailer, video}.
 * L'écrasement suppose la même extension : {ext} est celle du fichier envoyé,
 * donc un poster.png ne remplace pas un poster.jpg existant.
 *
 * Utilisé par StudioUploadController, qui valide `purpose` et `ext` avant
 * l'appel : ce service ne fait que composer le chemin. Le préfixe
 * `studios/{studio.slug}/` est celui qu'exige
 * StudioOwnershipChecker::assertBunnyPathOwnership().
 */
final class BunnyPathBuilder
{
    /**
     * Chemin d'un fichier de film : `studios/{studio.slug}/{film.slug}/{purpose}.{ext}`.
     *
     * @param string $purpose usage du fichier : poster, trailer ou video
     * @param string $ext     extension sans le point (ex. `jpg`, `mp4`)
     *
     * @throws \LogicException si le film n'est rattaché à aucun studio
     */
    public function forFilm(Film $film, string $purpose, string $ext): string
    {
        $studio = $film->getStudio();
        if ($studio === null) {
            throw new \LogicException('Film without studio cannot be assigned a Bunny path.');
        }

        return sprintf(
            'studios/%s/%s/%s.%s',
            $studio->getSlug(),
            $film->getSlug(),
            $purpose,
            $ext,
        );
    }

    /**
     * Chemin d'un fichier de série (affiche, bande-annonce) :
     * `studios/{studio.slug}/{serie.slug}/{purpose}.{ext}`.
     *
     * @param string $purpose usage du fichier : poster, trailer ou video
     * @param string $ext     extension sans le point
     *
     * @throws \LogicException si la série n'est rattachée à aucun studio
     */
    public function forSerie(Serie $serie, string $purpose, string $ext): string
    {
        $studio = $serie->getStudio();
        if ($studio === null) {
            throw new \LogicException('Serie without studio cannot be assigned a Bunny path.');
        }

        return sprintf(
            'studios/%s/%s/%s.%s',
            $studio->getSlug(),
            $serie->getSlug(),
            $purpose,
            $ext,
        );
    }

    /**
     * Chemin d'un fichier d'épisode, rangé dans le dossier de sa série :
     * `studios/{studio.slug}/{serie.slug}/saison-{N}/episode-{NN}/{purpose}.{ext}`.
     *
     * Le numéro d'épisode est complété à deux chiffres (7 → `episode-07`), pas celui
     * de la saison (`saison-1`).
     *
     * @param string $purpose usage du fichier (seul "video" est accepté en amont par
     *                        StudioUploadController pour un épisode)
     * @param string $ext     extension sans le point
     *
     * @throws \LogicException si la chaîne épisode → saison → série → studio est incomplète
     */
    public function forEpisode(Episode $episode, string $purpose, string $ext): string
    {
        // Contrôles défensifs : avec les propriétés typées non nullables de
        // Episode::$season et Season::$serie, seul le studio (nullable) peut
        // réellement manquer.
        $season = $episode->getSeason();
        if ($season === null) {
            throw new \LogicException('Episode without season cannot be assigned a Bunny path.');
        }
        $serie = $season->getSerie();
        if ($serie === null) {
            throw new \LogicException('Season without serie cannot be assigned a Bunny path.');
        }
        $studio = $serie->getStudio();
        if ($studio === null) {
            throw new \LogicException('Serie without studio cannot be assigned a Bunny path.');
        }

        return sprintf(
            'studios/%s/%s/saison-%d/episode-%02d/%s.%s',
            $studio->getSlug(),
            $serie->getSlug(),
            $season->getNumber(),
            $episode->getNumber(),
            $purpose,
            $ext,
        );
    }
}
