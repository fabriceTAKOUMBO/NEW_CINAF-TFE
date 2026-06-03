<?php
namespace App\Entity;

use App\Repository\WithdrawalRequestRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: WithdrawalRequestRepository::class)]
#[ORM\Table(name: 'withdrawal_request')]
#[ORM\Index(name: 'idx_withdrawal_target_status', columns: ['target_type', 'target_id', 'status'])]
class WithdrawalRequest
{
    public const TARGET_FILM = 'film';
    public const TARGET_SERIE = 'serie';

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Studio::class)]
    #[ORM\JoinColumn(name: 'studio_id', referencedColumnName: 'id', nullable: false)]
    private Studio $studio;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'requested_by_id', referencedColumnName: 'id', nullable: false)]
    private User $requestedBy;

    #[ORM\Column(length: 10)]
    private string $targetType;

    #[ORM\Column(type: 'uuid')]
    private Uuid $targetId;

    #[ORM\Column(type: 'text')]
    private string $reason;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'reviewed_by_id', referencedColumnName: 'id', nullable: true)]
    private ?User $reviewedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

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
     */
    public function reject(User $admin, ?string $note = null): void
    {
        $this->status = self::STATUS_REJECTED;
        $this->reviewedBy = $admin;
        $this->reviewedAt = new \DateTimeImmutable();
        $this->reviewNote = $note;
    }

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
