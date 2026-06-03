<?php
namespace App\Entity;

use App\Repository\SubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
#[ORM\Table(name: 'subscription')]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['user_id', 'status'], name: 'idx_subscription_user_status')]
class Subscription
{
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_CANCELED = 'CANCELED';
    public const STATUS_EXPIRED = 'EXPIRED';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: SubscriptionPlan::class)]
    #[ORM\JoinColumn(nullable: false)]
    private SubscriptionPlan $plan;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $canceledAt = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $stripeSubscriptionId = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->startsAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }
    public function getPlan(): SubscriptionPlan { return $this->plan; }
    public function setPlan(SubscriptionPlan $plan): static { $this->plan = $plan; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function getStartsAt(): \DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(\DateTimeImmutable $startsAt): static { $this->startsAt = $startsAt; return $this; }
    public function getEndsAt(): ?\DateTimeImmutable { return $this->endsAt; }
    public function setEndsAt(?\DateTimeImmutable $endsAt): static { $this->endsAt = $endsAt; return $this; }
    public function getCanceledAt(): ?\DateTimeImmutable { return $this->canceledAt; }
    public function setCanceledAt(?\DateTimeImmutable $canceledAt): static { $this->canceledAt = $canceledAt; return $this; }
    public function getStripeSubscriptionId(): ?string { return $this->stripeSubscriptionId; }
    public function setStripeSubscriptionId(?string $id): static { $this->stripeSubscriptionId = $id; return $this; }
    public function getStripeCustomerId(): ?string { return $this->stripeCustomerId; }
    public function setStripeCustomerId(?string $id): static { $this->stripeCustomerId = $id; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /**
     * Vrai si l'abonnement est actif et non expiré.
     */
    public function isCurrentlyActive(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }
        return $this->endsAt === null || $this->endsAt > new \DateTimeImmutable();
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'status' => $this->status,
            'startsAt' => $this->startsAt->format(\DateTimeInterface::ATOM),
            'endsAt' => $this->endsAt?->format(\DateTimeInterface::ATOM),
            'canceledAt' => $this->canceledAt?->format(\DateTimeInterface::ATOM),
            'isCurrentlyActive' => $this->isCurrentlyActive(),
            'plan' => [
                'id' => $this->plan->getId()->toRfc4122(),
                'name' => $this->plan->getName(),
                'priceCents' => $this->plan->getPriceCents(),
                'price' => $this->plan->getPriceCents() / 100,
                'formattedPrice' => $this->plan->getFormattedPrice(),
                'currency' => $this->plan->getCurrency(),
                'intervalUnit' => $this->plan->getIntervalUnit(),
                'intervalCount' => $this->plan->getIntervalCount(),
                'billingInterval' => $this->plan->getBillingInterval(),
                'features' => $this->plan->getFeatures(),
            ],
        ];
    }
}
