<?php

namespace App\Tests\Studio\Controller;

use App\Entity\Episode;
use App\Entity\Film;
use App\Entity\Season;
use App\Entity\Serie;
use App\Entity\Studio;
use App\Service\BunnyStorageService;
use App\Service\BunnyZoneRegistry;
use App\Tests\Studio\Support\StudioTestTrait;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Tests fonctionnels pour /api/studio/upload (convention "1 dossier par projet"
 * depuis 2026-05-08).
 *
 * L'endpoint reçoit (file, targetType, targetId, purpose) et délègue la
 * construction du path à BunnyPathBuilder.
 *
 * Les tests qui valident le path final mockent BunnyZoneRegistry pour ne
 * pas dépendre d'un vrai bucket Bunny.
 */
class StudioUploadControllerTest extends ApiTestCase
{
    use StudioTestTrait;

    // ─── Helpers locaux ──────────────────────────────────────

    private function smallPngFile(string $originalName = 'poster.png'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cinaf_png_');
        $im = imagecreatetruecolor(1, 1);
        imagesavealpha($im, true);
        imagepng($im, $tmp);
        imagedestroy($im);

        return new UploadedFile(
            path: $tmp,
            originalName: $originalName,
            mimeType: 'image/png',
            test: true,
        );
    }

    private function smallMp4File(string $originalName = 'video.mp4'): UploadedFile
    {
        // Pour le MIME : on annonce video/mp4 côté client, le controller
        // fait confiance au fileinfo OU au client MIME en fallback.
        $tmp = tempnam(sys_get_temp_dir(), 'cinaf_mp4_');
        // Pas un vrai MP4 mais ça suffit : si fileinfo détecte mal, le
        // fallback "clientMimeType" prend le relais.
        file_put_contents($tmp, "\x00\x00\x00\x20ftypisom" . str_repeat("\0", 64));

        return new UploadedFile(
            path: $tmp,
            originalName: $originalName,
            mimeType: 'video/mp4',
            test: true,
        );
    }

    /**
     * Mocke BunnyZoneRegistry pour que l'upload "réussisse" sans appel réel.
     * Retourne le path que BunnyStorageService::uploadFile reçoit (capturé).
     */
    private function mockBunnyAndCapture(): array
    {
        $captured = ['path' => null];
        $mockStorage = $this->createMock(BunnyStorageService::class);
        $mockStorage->method('uploadFile')
            ->willReturnCallback(function (UploadedFile $f, string $path) use (&$captured): string {
                $captured['path'] = $path;
                return 'https://fake-cdn.test/' . $path;
            });
        $mockRegistry = $this->createMock(BunnyZoneRegistry::class);
        $mockRegistry->method('get')->willReturn($mockStorage);

        try {
            static::getContainer()->set(BunnyZoneRegistry::class, $mockRegistry);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Cannot override BunnyZoneRegistry: ' . $e->getMessage());
        }

        return $captured;
    }

    private function persistFilm(Studio $studio, string $slug = null): Film
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $slug ??= 'test-film-' . bin2hex(random_bytes(3));
        $film = new Film();
        $film->setTitle('Test Film');
        $film->setSlug($slug);
        $film->setSynopsis('...');
        $film->setYear(2026);
        $film->setDuration(90);
        $film->setStudio($studio);
        $film->setStatus(Film::STATUS_DRAFT);
        $em->persist($film);
        $em->flush();
        return $film;
    }

    private function persistSerie(Studio $studio, string $slug = null): Serie
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $slug ??= 'test-serie-' . bin2hex(random_bytes(3));
        $serie = new Serie();
        $serie->setTitle('Test Serie');
        $serie->setSlug($slug);
        $serie->setSynopsis('...');
        $serie->setYear(2026);
        $serie->setStudio($studio);
        $serie->setStatus(Serie::STATUS_DRAFT);
        $em->persist($serie);
        $em->flush();
        return $serie;
    }

    private function persistEpisode(Serie $serie, int $seasonNumber = 1, int $episodeNumber = 1): Episode
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $season = new Season();
        $season->setSerie($serie);
        $season->setNumber($seasonNumber);
        $em->persist($season);

        $episode = new Episode();
        $episode->setSeason($season);
        $episode->setNumber($episodeNumber);
        $episode->setTitle('Episode ' . $episodeNumber);
        $episode->setDuration(45);
        $em->persist($episode);

        $em->flush();
        return $episode;
    }

    // ─── Validation du payload ───────────────────────────────

    public function testUploadMissingTargetTypeReturns400(): void
    {
        $client = static::createClient();
        [, , $token] = $this->createCreatorWithStudio();

        $client->request(
            'POST',
            '/api/studio/upload',
            ['purpose' => 'poster', 'targetId' => Uuid::v4()->toRfc4122()],
            ['file' => $this->smallPngFile()],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_ACCEPT' => 'application/json'],
        );

        $this->assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
    }

    public function testUploadInvalidPurposeReturns400(): void
    {
        $client = static::createClient();
        [, $studio, $token] = $this->createCreatorWithStudio();
        $film = $this->persistFilm($studio);

        $client->request(
            'POST',
            '/api/studio/upload',
            ['targetType' => 'film', 'targetId' => $film->getId()->toRfc4122(), 'purpose' => 'subtitles'],
            ['file' => $this->smallPngFile()],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_ACCEPT' => 'application/json'],
        );

        $this->assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
    }

    public function testUploadInvalidUuidReturns400(): void
    {
        $client = static::createClient();
        [, , $token] = $this->createCreatorWithStudio();

        $client->request(
            'POST',
            '/api/studio/upload',
            ['targetType' => 'film', 'targetId' => 'not-a-uuid', 'purpose' => 'poster'],
            ['file' => $this->smallPngFile()],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_ACCEPT' => 'application/json'],
        );

        $this->assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
    }

    public function testUploadFilmNotFoundReturns404(): void
    {
        $client = static::createClient();
        [, , $token] = $this->createCreatorWithStudio();

        $client->request(
            'POST',
            '/api/studio/upload',
            [
                'targetType' => 'film',
                'targetId'   => Uuid::v4()->toRfc4122(),
                'purpose'    => 'poster',
            ],
            ['file' => $this->smallPngFile()],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_ACCEPT' => 'application/json'],
        );

        $this->assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
    }

    // ─── Validation MIME / extension ─────────────────────────

    public function testUploadExtensionForgedReturns415(): void
    {
        $client = static::createClient();
        [, $studio, $token] = $this->createCreatorWithStudio();
        $film = $this->persistFilm($studio);

        $tmp = tempnam(sys_get_temp_dir(), 'cinaf_evil_');
        file_put_contents($tmp, "MZ\x90\x00fake-exe");
        $file = new UploadedFile(
            path: $tmp,
            originalName: 'malware.exe',
            mimeType: 'application/x-msdownload',
            test: true,
        );

        $client->request(
            'POST',
            '/api/studio/upload',
            ['targetType' => 'film', 'targetId' => $film->getId()->toRfc4122(), 'purpose' => 'video'],
            ['file' => $file],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_ACCEPT' => 'application/json'],
        );

        $this->assertSame(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, $client->getResponse()->getStatusCode());
    }

    public function testUploadPurposeMimeMismatchReturns415(): void
    {
        // purpose=poster + MIME video → 415
        $client = static::createClient();
        [, $studio, $token] = $this->createCreatorWithStudio();
        $film = $this->persistFilm($studio);

        $client->request(
            'POST',
            '/api/studio/upload',
            ['targetType' => 'film', 'targetId' => $film->getId()->toRfc4122(), 'purpose' => 'poster'],
            ['file' => $this->smallMp4File('trick.mp4')],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_ACCEPT' => 'application/json'],
        );

        $this->assertSame(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, $client->getResponse()->getStatusCode());
    }

    public function testUploadImageTooLargeReturns413(): void
    {
        $client = static::createClient();
        [, $studio, $token] = $this->createCreatorWithStudio();
        $film = $this->persistFilm($studio);

        $tmp = tempnam(sys_get_temp_dir(), 'cinaf_big_');
        $png = "\x89PNG\r\n\x1a\n" . str_repeat("\0", 11 * 1024 * 1024);
        file_put_contents($tmp, $png);
        $file = new UploadedFile(
            path: $tmp,
            originalName: 'huge.png',
            mimeType: 'image/png',
            test: true,
        );

        $client->request(
            'POST',
            '/api/studio/upload',
            ['targetType' => 'film', 'targetId' => $film->getId()->toRfc4122(), 'purpose' => 'poster'],
            ['file' => $file],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_ACCEPT' => 'application/json'],
        );

        $this->assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $client->getResponse()->getStatusCode());
    }

    // ─── Ownership ────────────────────────────────────────────

    public function testUploadOtherStudioFilmReturns403(): void
    {
        $client = static::createClient();
        [, , $token] = $this->createCreatorWithStudio();
        [, $otherStudio] = $this->createOtherCreatorWithStudio();
        $otherFilm = $this->persistFilm($otherStudio);

        $client->request(
            'POST',
            '/api/studio/upload',
            [
                'targetType' => 'film',
                'targetId'   => $otherFilm->getId()->toRfc4122(),
                'purpose'    => 'poster',
            ],
            ['file' => $this->smallPngFile()],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_ACCEPT' => 'application/json'],
        );

        $this->assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
    }

    public function testUploadEpisodeOtherSerieReturns403(): void
    {
        // L'épisode appartient à un autre studio (cascade ownership)
        $client = static::createClient();
        [, , $token] = $this->createCreatorWithStudio();
        [, $otherStudio] = $this->createOtherCreatorWithStudio();
        $otherSerie = $this->persistSerie($otherStudio);
        $otherEpisode = $this->persistEpisode($otherSerie);

        $client->request(
            'POST',
            '/api/studio/upload',
            [
                'targetType' => 'episode',
                'targetId'   => $otherEpisode->getId()->toRfc4122(),
                'purpose'    => 'video',
            ],
            ['file' => $this->smallMp4File()],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_ACCEPT' => 'application/json'],
        );

        $this->assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
    }

    // ─── Combinaisons non supportées ─────────────────────────

    public function testUploadEpisodePosterReturns400(): void
    {
        $client = static::createClient();
        [, $studio, $token] = $this->createCreatorWithStudio();
        $serie = $this->persistSerie($studio);
        $episode = $this->persistEpisode($serie);

        $client->request(
            'POST',
            '/api/studio/upload',
            [
                'targetType' => 'episode',
                'targetId'   => $episode->getId()->toRfc4122(),
                'purpose'    => 'poster',
            ],
            ['file' => $this->smallPngFile()],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_ACCEPT' => 'application/json'],
        );

        $this->assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
    }

    // ─── Construction du path (le cœur du refactor) ──────────

    public function testUploadFilmPosterBuildsExpectedPath(): void
    {
        $client = static::createClient();
        [, $studio, $token] = $this->createCreatorWithStudio();
        $slug = 'mon-film-' . bin2hex(random_bytes(3));
        $film = $this->persistFilm($studio, $slug);
        $this->mockBunnyAndCapture();

        $client->request(
            'POST',
            '/api/studio/upload',
            [
                'targetType' => 'film',
                'targetId'   => $film->getId()->toRfc4122(),
                'purpose'    => 'poster',
            ],
            ['file' => $this->smallPngFile()],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_ACCEPT' => 'application/json'],
        );

        $body = $this->assertJsonResponse($client->getResponse(), Response::HTTP_CREATED);
        $expected = sprintf('studios/%s/%s/poster.png', $studio->getSlug(), $slug);
        $this->assertSame($expected, $body['path']);
    }

    public function testUploadFilmVideoBuildsExpectedPath(): void
    {
        $client = static::createClient();
        [, $studio, $token] = $this->createCreatorWithStudio();
        $slug = 'film-video-' . bin2hex(random_bytes(3));
        $film = $this->persistFilm($studio, $slug);
        $this->mockBunnyAndCapture();

        $client->request(
            'POST',
            '/api/studio/upload',
            [
                'targetType' => 'film',
                'targetId'   => $film->getId()->toRfc4122(),
                'purpose'    => 'video',
            ],
            ['file' => $this->smallMp4File()],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_ACCEPT' => 'application/json'],
        );

        $body = $this->assertJsonResponse($client->getResponse(), Response::HTTP_CREATED);
        $expected = sprintf('studios/%s/%s/video.mp4', $studio->getSlug(), $slug);
        $this->assertSame($expected, $body['path']);
    }

    public function testUploadSeriePosterBuildsExpectedPath(): void
    {
        $client = static::createClient();
        [, $studio, $token] = $this->createCreatorWithStudio();
        $slug = 'ma-serie-' . bin2hex(random_bytes(3));
        $serie = $this->persistSerie($studio, $slug);
        $this->mockBunnyAndCapture();

        $client->request(
            'POST',
            '/api/studio/upload',
            [
                'targetType' => 'serie',
                'targetId'   => $serie->getId()->toRfc4122(),
                'purpose'    => 'poster',
            ],
            ['file' => $this->smallPngFile()],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_ACCEPT' => 'application/json'],
        );

        $body = $this->assertJsonResponse($client->getResponse(), Response::HTTP_CREATED);
        $expected = sprintf('studios/%s/%s/poster.png', $studio->getSlug(), $slug);
        $this->assertSame($expected, $body['path']);
    }

    public function testUploadEpisodeVideoBuildsExpectedPath(): void
    {
        $client = static::createClient();
        [, $studio, $token] = $this->createCreatorWithStudio();
        $slug = 'serie-ep-' . bin2hex(random_bytes(3));
        $serie = $this->persistSerie($studio, $slug);
        $episode = $this->persistEpisode($serie, seasonNumber: 1, episodeNumber: 7);
        $this->mockBunnyAndCapture();

        $client->request(
            'POST',
            '/api/studio/upload',
            [
                'targetType' => 'episode',
                'targetId'   => $episode->getId()->toRfc4122(),
                'purpose'    => 'video',
            ],
            ['file' => $this->smallMp4File()],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_ACCEPT' => 'application/json'],
        );

        $body = $this->assertJsonResponse($client->getResponse(), Response::HTTP_CREATED);
        $expected = sprintf('studios/%s/%s/saison-1/episode-07/video.mp4', $studio->getSlug(), $slug);
        $this->assertSame($expected, $body['path']);
    }

    public function testUploadNoFileReturns400(): void
    {
        $client = static::createClient();
        [, $studio, $token] = $this->createCreatorWithStudio();
        $film = $this->persistFilm($studio);

        $client->request(
            'POST',
            '/api/studio/upload',
            ['targetType' => 'film', 'targetId' => $film->getId()->toRfc4122(), 'purpose' => 'poster'],
            [], // pas de file
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_ACCEPT' => 'application/json'],
        );

        $this->assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
    }
}
