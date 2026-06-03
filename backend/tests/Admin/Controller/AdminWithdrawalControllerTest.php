<?php

namespace App\Tests\Admin\Controller;

use App\Entity\Film;
use App\Entity\Serie;
use App\Entity\Studio;
use App\Entity\User;
use App\Entity\WithdrawalRequest;
use App\Tests\Studio\Support\StudioTestTrait;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Tests fonctionnels pour /api/admin/withdrawals (Phase C).
 */
class AdminWithdrawalControllerTest extends ApiTestCase
{
    use StudioTestTrait;

    private function createFilmFor(Studio $studio, string $title, string $status = Film::STATUS_PUBLISHED): Film
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $slugger = new AsciiSlugger();

        $film = new Film();
        $film->setTitle($title);
        $film->setSlug(strtolower((string) $slugger->slug($title . '-' . bin2hex(random_bytes(3)))));
        $film->setSynopsis('Synopsis');
        $film->setYear(2024);
        $film->setDuration(95);
        $film->setStudio($studio);
        $film->setStatus($status);
        if ($status === Film::STATUS_PUBLISHED) {
            $film->setPublishedAt(new \DateTimeImmutable());
        } elseif ($status === Film::STATUS_WITHDRAWN) {
            $film->setWithdrawnAt(new \DateTimeImmutable());
        }
        $em->persist($film);
        $em->flush();

        return $film;
    }

    private function createSerieFor(Studio $studio, string $title, string $status = Serie::STATUS_PUBLISHED): Serie
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $slugger = new AsciiSlugger();

        $serie = new Serie();
        $serie->setTitle($title);
        $serie->setSlug(strtolower((string) $slugger->slug($title . '-' . bin2hex(random_bytes(3)))));
        $serie->setSynopsis('Synopsis serie');
        $serie->setYear(2024);
        $serie->setStudio($studio);
        $serie->setStatus($status);
        if ($status === Serie::STATUS_PUBLISHED) {
            $serie->setPublishedAt(new \DateTimeImmutable());
        }
        $em->persist($serie);
        $em->flush();

        return $serie;
    }

    private function createWithdrawal(
        Studio $studio,
        User $owner,
        string $targetType,
        \Symfony\Component\Uid\Uuid $targetId,
        string $status = WithdrawalRequest::STATUS_PENDING,
    ): WithdrawalRequest {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $w = new WithdrawalRequest();
        $w->setStudio($studio);
        $w->setRequestedBy($owner);
        $w->setTargetType($targetType);
        $w->setTargetId($targetId);
        $w->setReason('Test reason');
        $w->setStatus($status);
        $em->persist($w);
        $em->flush();

        return $w;
    }

    public function test_admin_lists_pending(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [$owner, $studio] = $this->createCreatorWithStudio();
        $f1 = $this->createFilmFor($studio, 'WR-Film-1');
        $f2 = $this->createFilmFor($studio, 'WR-Film-2');
        $f3 = $this->createFilmFor($studio, 'WR-Film-3');
        $w1 = $this->createWithdrawal($studio, $owner, 'film', $f1->getId(), WithdrawalRequest::STATUS_PENDING);
        $w2 = $this->createWithdrawal($studio, $owner, 'film', $f2->getId(), WithdrawalRequest::STATUS_PENDING);
        $w3 = $this->createWithdrawal($studio, $owner, 'film', $f3->getId(), WithdrawalRequest::STATUS_APPROVED);

        $response = $this->getJson($client, '/api/admin/withdrawals?status=PENDING&limit=50', $token);
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $ids = array_column($body['data'], 'id');
        $this->assertContains($w1->getId()->toRfc4122(), $ids);
        $this->assertContains($w2->getId()->toRfc4122(), $ids);
        $this->assertNotContains($w3->getId()->toRfc4122(), $ids);
        foreach ($body['data'] as $row) {
            $this->assertSame('PENDING', $row['status']);
            $this->assertArrayHasKey('studio', $row);
            $this->assertArrayHasKey('requestedBy', $row);
        }
    }

    public function test_admin_approves_sets_film_withdrawn(): void
    {
        [$client, $token, $admin] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [$owner, $studio] = $this->createCreatorWithStudio();
        $film = $this->createFilmFor($studio, 'ApprovedFilm', Film::STATUS_PUBLISHED);
        $w = $this->createWithdrawal($studio, $owner, 'film', $film->getId());

        $response = $this->postJson(
            $client,
            '/api/admin/withdrawals/' . $w->getId()->toRfc4122() . '/approve',
            ['reviewNote' => 'OK'],
            $token,
        );
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $this->assertSame('APPROVED', $body['withdrawal']['status']);
        $this->assertSame($admin->getId()->toRfc4122(), $body['withdrawal']['reviewedById']);
        $this->assertNotNull($body['withdrawal']['reviewedAt']);
        $this->assertSame('WITHDRAWN', $body['target']['status']);

        // Vérif DB
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloadedFilm = $em->getRepository(Film::class)->find($film->getId());
        $this->assertSame(Film::STATUS_WITHDRAWN, $reloadedFilm->getStatus());
        $this->assertNotNull($reloadedFilm->getWithdrawnAt());

        $reloadedW = $em->getRepository(WithdrawalRequest::class)->find($w->getId());
        $this->assertSame(WithdrawalRequest::STATUS_APPROVED, $reloadedW->getStatus());
        $this->assertNotNull($reloadedW->getReviewedBy());
        $this->assertSame($admin->getId()->toRfc4122(), $reloadedW->getReviewedBy()->getId()->toRfc4122());
    }

    public function test_admin_approves_sets_serie_withdrawn(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [$owner, $studio] = $this->createCreatorWithStudio();
        $serie = $this->createSerieFor($studio, 'ApprovedSerie', Serie::STATUS_PUBLISHED);
        $w = $this->createWithdrawal($studio, $owner, 'serie', $serie->getId());

        $response = $this->postJson(
            $client,
            '/api/admin/withdrawals/' . $w->getId()->toRfc4122() . '/approve',
            [],
            $token,
        );
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $this->assertSame('APPROVED', $body['withdrawal']['status']);
        $this->assertSame('WITHDRAWN', $body['target']['status']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $em->getRepository(Serie::class)->find($serie->getId());
        $this->assertSame(Serie::STATUS_WITHDRAWN, $reloaded->getStatus());
        $this->assertNotNull($reloaded->getWithdrawnAt());
    }

    public function test_admin_rejects_keeps_published(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [$owner, $studio] = $this->createCreatorWithStudio();
        $film = $this->createFilmFor($studio, 'RejectedFilm', Film::STATUS_PUBLISHED);
        $w = $this->createWithdrawal($studio, $owner, 'film', $film->getId());

        $response = $this->postJson(
            $client,
            '/api/admin/withdrawals/' . $w->getId()->toRfc4122() . '/reject',
            ['reviewNote' => 'Pas de raison valable'],
            $token,
        );
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $this->assertSame('REJECTED', $body['status']);
        $this->assertSame('Pas de raison valable', $body['reviewNote']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloadedFilm = $em->getRepository(Film::class)->find($film->getId());
        $this->assertSame(Film::STATUS_PUBLISHED, $reloadedFilm->getStatus());

        $reloadedW = $em->getRepository(WithdrawalRequest::class)->find($w->getId());
        $this->assertSame(WithdrawalRequest::STATUS_REJECTED, $reloadedW->getStatus());
        $this->assertSame('Pas de raison valable', $reloadedW->getReviewNote());
    }

    public function test_approve_already_processed_409(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [$owner, $studio] = $this->createCreatorWithStudio();
        $film = $this->createFilmFor($studio, 'AlreadyApproved');
        $w = $this->createWithdrawal($studio, $owner, 'film', $film->getId());

        // 1er approve : succès
        $first = $this->postJson(
            $client,
            '/api/admin/withdrawals/' . $w->getId()->toRfc4122() . '/approve',
            [],
            $token,
        );
        $this->assertSame(Response::HTTP_OK, $first->getStatusCode());

        // 2e approve : 409
        $second = $this->postJson(
            $client,
            '/api/admin/withdrawals/' . $w->getId()->toRfc4122() . '/approve',
            [],
            $token,
        );
        $this->assertSame(Response::HTTP_CONFLICT, $second->getStatusCode());
    }

    public function test_approve_target_already_withdrawn_409(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [$owner, $studio] = $this->createCreatorWithStudio();
        // Film déjà WITHDRAWN dès le départ
        $film = $this->createFilmFor($studio, 'AlreadyWithdrawnFilm', Film::STATUS_WITHDRAWN);
        $w = $this->createWithdrawal($studio, $owner, 'film', $film->getId());

        $response = $this->postJson(
            $client,
            '/api/admin/withdrawals/' . $w->getId()->toRfc4122() . '/approve',
            [],
            $token,
        );
        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    public function test_admin_withdrawal_non_admin_403(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_USER');
        $response = $this->getJson($client, '/api/admin/withdrawals', $token);
        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }
}
