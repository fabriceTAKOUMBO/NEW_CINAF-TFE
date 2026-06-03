<?php
namespace App\Entity;

use App\Repository\SubscriptionPlanRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SubscriptionPlanRepository::class)]
#[ORM\Table(name: 'subscription_plan')]
#[ORM\HasLifecycleCallbacks]
class SubscriptionPlan
{
    public const INTERVAL_MONTH = 'month';
    public const INTERVAL_YEAR = 'year';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** Prix en centimes (999 = 9.99€). */
    #[ORM\Column(type: 'integer')]
    private int $priceCents;

    #[ORM\Column(length: 3)]
    private string $currency = 'EUR';

    /** "month" ou "year". */
    #[ORM\Column(length: 10)]
    private string $intervalUnit = self::INTERVAL_MONTH;

    #[ORM\Column(type: 'integer')]
    private int $intervalCount = 1;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $features = [];

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    /** ID Stripe pour migration future ; null tant qu'on est en mode mock. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $stripePriceId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
    public function getPriceCents(): int { return $this->priceCents; }
    public function setPriceCents(int $priceCents): static { $this->priceCents = $priceCents; return $this; }
    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $currency): static { $this->currency = $currency; return $this; }
    public function getIntervalUnit(): string { return $this->intervalUnit; }
    public function setIntervalUnit(string $intervalUnit): static { $this->intervalUnit = $intervalUnit; return $this; }
    public function getIntervalCount(): int { return $this->intervalCount; }
    public function setIntervalCount(int $intervalCount): static { $this->intervalCount = $intervalCount; return $this; }
    /** @return list<string> */
    public function getFeatures(): array { return $this->features; }
    /** @param list<string> $features */
    public function setFeatures(array $features): static { $this->features = $features; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): static { $this->isActive = $isActive; return $this; }
    public function getStripePriceId(): ?string { return $this->stripePriceId; }
    public function setStripePriceId(?string $stripePriceId): static { $this->stripePriceId = $stripePriceId; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function getFormattedPrice(): string
    {
        return number_format($this->priceCents / 100, 2, ',', ' ') . ' ' . $this->currency;
    }

    public function getBillingInterval(): string
    {
        return $this->intervalUnit === self::INTERVAL_YEAR ? 'year' : 'month';
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'name' => $this->name,
            'description' => $this->description,
            'priceCents' => $this->priceCents,
            'price' => $this->priceCents / 100,
            'formattedPrice' => $this->getFormattedPrice(),
            'currency' => $this->currency,
            'intervalUnit' => $this->intervalUnit,
            'intervalCount' => $this->intervalCount,
            'billingInterval' => $this->getBillingInterval(),
            'features' => $this->features,
            'isActive' => $this->isActive,
            'trialDays' => 0,
        ];
    }
}
