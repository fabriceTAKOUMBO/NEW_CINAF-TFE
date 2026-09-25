<?php

namespace App\Tests\Entity;

use App\Entity\Studio;
use App\Entity\User;
use App\Entity\WithdrawalRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Pure unit tests for the WithdrawalRequest entity (no DB, no kernel boot).
 *
 * Tests unitaires de la demande de retrait, sans base ni kernel : valeurs par
 * défaut (PENDING), cible polymorphe (type + UUID), transitions approve() /
 * reject() et forme de toArray(). Le passage du contenu en WITHDRAWN n'est
 * pas couvert ici : il relève de l'appelant (voir AdminWithdrawalControllerTest).
 *
 * Lancement : php bin/phpunit tests/Entity/WithdrawalRequestTest.php
 */
class WithdrawalRequestTest extends TestCase
{
    /**
     * Une demande neuve a un UUID, une date de création, le statut PENDING et
     * aucune information de revue (relecteur, date, note).
     */
    public function testConstructorInitializesDefaults(): void
    {
        $req = new WithdrawalRequest();

        $this->assertInstanceOf(Uuid::class, $req->getId());
        $this->assertInstanceOf(\DateTimeImmutable::class, $req->getCreatedAt());
        $this->assertSame(WithdrawalRequest::STATUS_PENDING, $req->getStatus());
        $this->assertNull($req->getReviewedBy());
        $this->assertNull($req->getReviewedAt());
        $this->assertNull($req->getReviewNote());
    }

    /** Le type de cible (TARGET_FILM = 'film') et l'UUID du contenu visé sont conservés tels quels. */
    public function testTargetTypeAndIdAreStored(): void
    {
        $targetId = Uuid::v4();
        $req = $this->makePending();
        $req->setTargetType(WithdrawalRequest::TARGET_FILM)
            ->setTargetId($targetId);

        $this->assertSame('film', $req->getTargetType());
        $this->assertSame(WithdrawalRequest::TARGET_FILM, $req->getTargetType());
        $this->assertSame($targetId, $req->getTargetId());
    }

    /** approve() avec note : PENDING → APPROVED, relecteur, date de revue et note enregistrés. */
    public function testApproveTransition(): void
    {
        $req = $this->makePending();
        $admin = $this->makeAdmin();

        $this->assertSame(WithdrawalRequest::STATUS_PENDING, $req->getStatus());

        $req->approve($admin, 'OK, validé par modération.');

        $this->assertSame(WithdrawalRequest::STATUS_APPROVED, $req->getStatus());
        $this->assertSame('APPROVED', $req->getStatus());
        $this->assertSame($admin, $req->getReviewedBy());
        $this->assertInstanceOf(\DateTimeImmutable::class, $req->getReviewedAt());
        $this->assertSame('OK, validé par modération.', $req->getReviewNote());
    }

    /** approve() sans note : statut APPROVED, relecteur et date renseignés, note null. */
    public function testApproveWithoutNote(): void
    {
        $req = $this->makePending();
        $admin = $this->makeAdmin();

        $req->approve($admin);

        $this->assertSame(WithdrawalRequest::STATUS_APPROVED, $req->getStatus());
        $this->assertSame($admin, $req->getReviewedBy());
        $this->assertNotNull($req->getReviewedAt());
        $this->assertNull($req->getReviewNote());
    }

    /** reject() avec note : statut REJECTED, relecteur, date de revue et note enregistrés. */
    public function testRejectTransition(): void
    {
        $req = $this->makePending();
        $admin = $this->makeAdmin();

        $req->reject($admin, 'Motif insuffisant.');

        $this->assertSame(WithdrawalRequest::STATUS_REJECTED, $req->getStatus());
        $this->assertSame('REJECTED', $req->getStatus());
        $this->assertSame($admin, $req->getReviewedBy());
        $this->assertInstanceOf(\DateTimeImmutable::class, $req->getReviewedAt());
        $this->assertSame('Motif insuffisant.', $req->getReviewNote());
    }

    /**
     * toArray() d'une demande en attente visant une série : targetType 'serie',
     * targetId en RFC 4122, statut PENDING, motif, et champs de revue à null.
     */
    public function testToArrayShape(): void
    {
        $targetId = Uuid::v4();
        $req = $this->makePending();
        $req->setTargetType(WithdrawalRequest::TARGET_SERIE)
            ->setTargetId($targetId)
            ->setReason('Réécriture demandée par le réalisateur.');

        $arr = $req->toArray();

        $this->assertArrayHasKey('id', $arr);
        $this->assertSame('serie', $arr['targetType']);
        $this->assertSame($targetId->toRfc4122(), $arr['targetId']);
        $this->assertSame('PENDING', $arr['status']);
        $this->assertSame('Réécriture demandée par le réalisateur.', $arr['reason']);
        $this->assertNull($arr['reviewedById']);
        $this->assertNull($arr['reviewedAt']);
        $this->assertNull($arr['reviewNote']);
        $this->assertArrayHasKey('createdAt', $arr);
    }

    /** Demande PENDING complète (studio et demandeur factices, cible film), non persistée. */
    private function makePending(): WithdrawalRequest
    {
        $studio = new Studio();
        $studio->setName('Test Studio')
            ->setSlug('test-studio')
            ->setBunnyFolder('studios/test-studio/')
            ->setOwner($this->makeOwner());

        $req = new WithdrawalRequest();
        $req->setStudio($studio)
            ->setRequestedBy($this->makeOwner())
            ->setTargetType(WithdrawalRequest::TARGET_FILM)
            ->setTargetId(Uuid::v4())
            ->setReason('Default reason for tests.');

        return $req;
    }

    /** Utilisateur studio factice (ROLE_CREATEUR) à email unique, non persisté. */
    private function makeOwner(): User
    {
        $u = new User();
        $u->setEmail('owner+' . uniqid('', true) . '@example.com');
        $u->setFirstName('Producer');
        $u->setLastName('Test');
        $u->setPassword('hashed');
        $u->setRoles(['ROLE_CREATEUR']);
        return $u;
    }

    /** Administrateur factice (ROLE_ADMIN) à email unique, non persisté. */
    private function makeAdmin(): User
    {
        $u = new User();
        $u->setEmail('admin+' . uniqid('', true) . '@example.com');
        $u->setFirstName('Admin');
        $u->setLastName('Test');
        $u->setPassword('hashed');
        $u->setRoles(['ROLE_ADMIN']);
        return $u;
    }
}
