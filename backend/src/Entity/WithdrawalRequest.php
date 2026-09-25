<?php
namespace App\Entity;

use App\Repository\WithdrawalRequestRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Demande de retrait d'un contenu publié, déposée par un studio et
 * arbitrée par un administrateur.
 *
 * Flux : le studio dépose la demande (POST /api/studio/films/{id}/withdraw
 * ou /api/studio/series/{id}/withdraw, via
 * ContentLifecycleService::requestWithdrawal()) en statut PENDING ; un admin
 * l'approuve — le contenu passe alors en WITHDRAWN — ou la rejette, depuis
 * /api/admin/withdrawals.
 *
 * La cible est polymorphe (`targetType` + `targetId`) : il n'y a donc pas de
 * clé étrangère vers le contenu. Un index unique PARTIEL `uniq_withdrawal_pending`
 * (target_type, target_id) WHERE status = 'PENDING', créé en SQL natif par la
 * migration Version20260430100200, interdit deux demandes en attente pour un
 * même contenu. Invisible pour l'ORM (d'où un `DROP INDEX` parasite dans
 * chaque `migrations:diff`), il est doublé d'un contrôle applicatif (409).
 */
#[ORM\Entity(repositoryClass: WithdrawalRequestRepository::class)]
#[ORM\Table(name: 'withdrawal_request')]
#[ORM\Index(name: 'idx_withdrawal_target_status', columns: ['target_type', 'target_id', 'status'])]
class WithdrawalRequest
{
    /** Valeurs possibles de `targetType`. */
    public const TARGET_FILM = 'film';
    public const TARGET_SERIE = 'serie';

    /** En attente de décision admin (statut initial). */
    public const STATUS_PENDING = 'PENDING';
    /** Acceptée : le contenu visé a été passé en WITHDRAWN. */
    public const STATUS_APPROVED = 'APPROVED';
    /** Refusée : le contenu visé reste publié. */
    public const STATUS_REJECTED = 'REJECTED';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /** Studio propriétaire du contenu visé. */
    #[ORM\ManyToOne(targetEntity: Studio::class)]
    #[ORM\JoinColumn(name: 'studio_id', referencedColumnName: 'id', nullable: false)]
    private Studio $studio;

    /** Utilisateur (compte studio) qui a déposé la demande. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'requested_by_id', referencedColumnName: 'id', nullable: false)]
    private User $requestedBy;

    /** Type du contenu visé : TARGET_FILM ou TARGET_SERIE. */
    #[ORM\Column(length: 10)]
    private string $targetType;

    /** UUID du Film ou de la Serie visé — référence polymorphe, sans FK. */
    #[ORM\Column(type: 'uuid')]
    private Uuid $targetId;

    /** Motif de la demande, saisi par le studio. */
    #[ORM\Column(type: 'text')]
    private string $reason;

    /** Une des constantes STATUS_* (PENDING par défaut). */
    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    /** Administrateur ayant statué ; null tant que la demande est en attente. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'reviewed_by_id', referencedColumnName: 'id', nullable: true)]
    private ?User $reviewedBy = null;

    /** Date de la décision (approbation ou rejet). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    /** Commentaire facultatif de l'administrateur. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reviewNote = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getStudio(): Studio { return $this->studio; }
    public function setStudio(Studio $studio): static { $this->studio = $studio; return $this; }
    public function getRequestedBy(): User { return $this->requestedBy; }
    public function setRequestedBy(User $requestedBy): static { $this->requestedBy = $requestedBy; return $this; }
    public function getTargetType(): string { return $this->targetType; }
    public function setTargetType(string $targetType): static { $this->targetType = $targetType; return $this; }
    public function getTargetId(): Uuid { return $this->targetId; }
    public function setTargetId(Uuid $targetId): static { $this->targetId = $targetId; return $this; }
    public function getReason(): string { return $this->reason; }
    public function setReason(string $reason): static { $this->reason = $reason; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function getReviewedBy(): ?User { return $this->reviewedBy; }
    public function setReviewedBy(?User $reviewedBy): static { $this->reviewedBy = $reviewedBy; return $this; }
    public function getReviewedAt(): ?\DateTimeImmutable { return $this->reviewedAt; }
    public function setReviewedAt(?\DateTimeImmutable $reviewedAt): static { $this->reviewedAt = $reviewedAt; return $this; }
    public function getReviewNote(): ?string { return $this->reviewNote; }
    public function setReviewNote(?string $reviewNote): static { $this->reviewNote = $reviewNote; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /**
     * Approve this request: set status, reviewer, timestamp and optional note.
     *
     * N'enregistre que la décision : le passage du contenu en WITHDRAWN est
     * fait par l'appelant (AdminWithdrawalController, via
     * ContentLifecycleService). Aucun contrôle du statut courant ici :
     * l'appelant vérifie que la demande est bien PENDING.
     */
    public function approve(User $admin, ?string $note = null): void
    {
        $this->status = self::STATUS_APPROVED;
        $this->reviewedBy = $admin;
        $this->reviewedAt = new \DateTimeImmutable();
        $this->reviewNote = $note;
    }

    /**
     * Reject this request: set status, reviewer, timestamp and optional note.
     *
     * Le contenu visé n'est pas modifié (il reste publié). Comme approve(),
     * ne vérifie pas le statut courant : c'est le rôle de l'appelant.
     */
    public function reject(User $admin, ?string $note = null): void
    {
        $this->status = self::STATUS_REJECTED;
        $this->reviewedBy = $admin;
        $this->reviewedAt = new \DateTimeImmutable();
        $this->reviewNote = $note;
    }

    /**
     * Sérialise la demande pour l'API : id, studioId, requestedById,
     * targetType, targetId, reason, status, reviewedById, reviewedAt,
     * reviewNote, createdAt (UUID en RFC 4122, dates ATOM ou null).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'studioId' => $this->studio->getId()->toRfc4122(),
            'requestedById' => $this->requestedBy->getId()->toRfc4122(),
            'targetType' => $this->targetType,
            'targetId' => $this->targetId->toRfc4122(),
            'reason' => $this->reason,
            'status' => $this->status,
            'reviewedById' => $this->reviewedBy?->getId()->toRfc4122(),
            'reviewedAt' => $this->reviewedAt?->format(\DateTimeInterface::ATOM),
            'reviewNote' => $this->reviewNote,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
