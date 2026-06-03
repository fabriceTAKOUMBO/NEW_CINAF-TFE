<?php

declare(strict_types=1);

namespace App\Tests\Studio\Service;

use App\Entity\Episode;
use App\Entity\Film;
use App\Entity\Season;
use App\Entity\Serie;
use App\Entity\Studio;
use App\Entity\User;
use App\Studio\Service\BunnyPathBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de App\Studio\Service\BunnyPathBuilder.
 *
 * Pas de DB : on construit les entités à la main. Les setters de Season et
 * Episode prennent leur parent en non-null typé (`Season $s`, `Serie $s`),
 * ce qui rend impossible la création d'un Episode sans Season ou d'une
 * Season sans Serie *via l'API publique des entités*. Les LogicException
 * correspondantes dans BunnyPathBuilder sont du code défensif inateignable
 * tant que les signatures restent non-null — on ne les teste pas.
 *
 * En revanche `Film::studio`, `Serie::studio` sont nullable côté entité,
 * donc on peut tester ces deux cas LogicException.
 */
final class BunnyPathBuilderTest extends TestCase
{
    private BunnyPathBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new BunnyPathBuilder();
    }

    // ─── Construction de path — cas nominaux ─────────────────────

    public function testForFilmBuildsExpectedPath(): void
    {
        $studio = $this->makeStudio('nollywood-studios');
        $film = (new Film())->setSlug('le-grand-film')->setStudio($studio);

        $path = $this->builder->forFilm($film, 'poster', 'jpg');

        $this->assertSame('studios/nollywood-studios/le-grand-film/poster.jpg', $path);
    }

    public function testForFilmHandlesVideoMp4(): void
    {
        $studio = $this->makeStudio('cinaf-films');
        $film = (new Film())->setSlug('rocky')->setStudio($studio);

        $this->assertSame(
            'studios/cinaf-films/rocky/video.mp4',
            $this->builder->forFilm($film, 'video', 'mp4'),
        );
    }

    public function testForSerieBuildsExpectedPath(): void
    {
        $studio = $this->makeStudio('canal-plus-afrique');
        $serie = (new Serie())->setSlug('sakho-mangane')->setStudio($studio);

        $this->assertSame(
            'studios/canal-plus-afrique/sakho-mangane/poster.jpg',
            $this->builder->forSerie($serie, 'poster', 'jpg'),
        );
    }

    public function testForEpisodePadsEpisodeNumberToTwoDigits(): void
    {
        $studio = $this->makeStudio('studio-x');
        $serie = (new Serie())->setSlug('serie-y')->setStudio($studio);
        $season = (new Season())->setSerie($serie)->setNumber(1);
        $episode = (new Episode())->setSeason($season)->setNumber(7);

        $this->assertSame(
            'studios/studio-x/serie-y/saison-1/episode-07/video.mp4',
            $this->builder->forEpisode($episode, 'video', 'mp4'),
        );
    }

    public function testForEpisodeKeepsDoubleDigitNumber(): void
    {
        $studio = $this->makeStudio('studio-x');
        $serie = (new Serie())->setSlug('serie-y')->setStudio($studio);
        $season = (new Season())->setSerie($serie)->setNumber(2);
        $episode = (new Episode())->setSeason($season)->setNumber(15);

        $this->assertSame(
            'studios/studio-x/serie-y/saison-2/episode-15/video.mp4',
            $this->builder->forEpisode($episode, 'video', 'mp4'),
        );
    }

    public function testDeterministicOverwrite(): void
    {
        // Deux constructions successives avec le même purpose donnent
        // le même path → le 2e upload écrase le 1er sur Bunny.
        $studio = $this->makeStudio('s');
        $film = (new Film())->setSlug('f')->setStudio($studio);

        $first  = $this->builder->forFilm($film, 'video', 'mp4');
        $second = $this->builder->forFilm($film, 'video', 'mp4');

        $this->assertSame($first, $second);
    }

    // ─── LogicException : entité sans studio ─────────────────────

    public function testForFilmWithoutStudioThrows(): void
    {
        $film = (new Film())->setSlug('orphan');
        // Pas de setStudio() → studio reste null (champ nullable côté entité).

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Film without studio');

        $this->builder->forFilm($film, 'video', 'mp4');
    }

    public function testForSerieWithoutStudioThrows(): void
    {
        $serie = (new Serie())->setSlug('orphan');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Serie without studio');

        $this->builder->forSerie($serie, 'poster', 'jpg');
    }

    public function testForEpisodeWithoutStudioOnSerieThrows(): void
    {
        // Serie sans studio (le setter accepte null) → l'épisode hérite
        // de l'orphelinat et BunnyPathBuilder le rejette explicitement.
        $serie = (new Serie())->setSlug('s');
        $season = (new Season())->setSerie($serie)->setNumber(1);
        $episode = (new Episode())->setSeason($season)->setNumber(1);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Serie without studio');

        $this->builder->forEpisode($episode, 'video', 'mp4');
    }

    // ─── Helpers ──────────────────────────────────────────────────

    private function makeStudio(string $slug): Studio
    {
        $studio = new Studio();
        $studio->setName('Test ' . $slug);
        $studio->setSlug($slug);
        $studio->setOwner(new User());
        $studio->setBunnyFolder('studios/' . $slug . '/');
        $studio->setIsActive(true);
        return $studio;
    }
}
