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
 *
 * Contrôleur testé : App\Admin\Controller\AdminWithdrawalController. Un studio
 * ne retire pas lui-même un contenu publié : il dépose une demande de retrait
 * (WithdrawalRequest, PENDING) que l'admin approuve (demande APPROVED, contenu
 * WITHDRAWN avec `withdrawnAt`) ou refuse (demande REJECTED, contenu inchangé).
 * Vérifié : le filtre de la file par statut, l'enregistrement du relecteur
 * (l'admin connecté), de la date et de la note, les deux cas 409 (demande déjà
 * traitée, contenu déjà retiré) et le 403 pour un non-admin.
 *
 * Données : contenus et demandes sont créés directement en base par les
 * helpers ci-dessous (sans passer par l'API studio), ce qui permet de partir
 * de n'importe quel statut. Chaque demande vise un contenu différent : l'index
 * unique partiel `uniq_withdrawal_pending` interdit deux demandes PENDING pour
 * un même contenu.
 *
 * Lancement : `php bin/phpunit tests/Admin/Controller/AdminWithdrawalControllerTest.php`.
 */
class AdminWithdrawalControllerTest extends ApiTestCase
{
    use StudioTestTrait;

    /**
     * Persiste un film du studio donné, PUBLISHED par défaut, en renseignant
     * `publishedAt` (PUBLISHED) ou `withdrawnAt` (WITHDRAWN) selon le statut
     * demandé. Slug AsciiSlugger (titre + suffixe aléatoire) pour rester unique.
     */
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

    /**
     * Persiste une série (sans saison) du studio donné, PUBLISHED par défaut
     * (avec `publishedAt` dans ce cas).
     */
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

    /**
     * Persiste directement une demande de retrait (PENDING par défaut) déposée
     * par `$owner` pour le contenu `$targetType` (`film` ou `serie`) /
     * `$targetId`, sans passer par l'endpoint studio : on peut ainsi créer une
     * demande déjà APPROVED pour les besoins d'un test.
     */
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

    /**
     * File filtrée `?status=PENDING` : les deux demandes en attente créées ici
     * sont listées, pas celle déjà APPROVED ; chaque ligne est PENDING et porte
     * les résumés `studio` et `requestedBy` (200).
     */
    public function test_admin_lists_pending(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [$owner, $studio] = $this->createCreatorWithStudio();
        $f1 = $this->createFilmFor($studio, 'WR-Film-1');
        $f2 = $this->createFilmFor($studio, 'WR-Film-2');
        $f3 = $this->createFilmFor($studio, 'WR-Film-3');
        // Une demande par film : deux PENDING et une déjà APPROVED, qui ne doit
        // pas apparaître dans la file filtrée.
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

    /**
     * Approbation d'une demande visant un film publié : 200 ; la demande passe
     * APPROVED avec l'admin connecté comme relecteur (`reviewedById`) et une date
     * de relecture, et le film renvoyé (`target`) est WITHDRAWN. En base : film
     * WITHDRAWN avec `withdrawnAt`, demande APPROVED relue par cet admin.
     */
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

    /**
     * Même scénario pour une série, sans note de relecture (corps JSON sans
     * `reviewNote`, la note étant facultative) : 200, demande APPROVED, série
     * WITHDRAWN avec `withdrawnAt` en base.
     */
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

    /**
     * Refus d'une demande avec une note : 200, la demande renvoyée est REJECTED
     * et porte la note ; en base, le film reste PUBLISHED et la demande est
     * REJECTED avec sa note enregistrée.
     */
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

        // Contrairement à approve (`{withdrawal, target}`), reject renvoie
        // directement la demande sérialisée, d'où `$body['status']`.
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

    /**
     * Une demande ne se traite qu'une fois : la 1re approbation répond 200, la
     * 2e 409 Conflict (demande déjà traitée).
     */
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

    /**
     * Demande encore PENDING mais visant un film déjà WITHDRAWN : son
     * approbation est refusée, 409 Conflict (contenu déjà retiré).
     */
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

    /** Un utilisateur ROLE_USER n'accède pas à la file des demandes de retrait : 403. */
    public function test_admin_withdrawal_non_admin_403(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_USER');
        $response = $this->getJson($client, '/api/admin/withdrawals', $token);
        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }
}
