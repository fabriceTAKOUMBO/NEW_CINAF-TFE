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
 */
final class BunnyPathBuilder
{
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

    public function forEpisode(Episode $episode, string $purpose, string $ext): string
    {
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
