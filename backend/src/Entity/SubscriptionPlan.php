<?php
namespace App\Entity;

use App\Repository\SubscriptionPlanRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Formule d'abonnement payant proposée à la vente (Mensuel 9,99 €, Annuel
 * 99 € dans les fixtures), listée publiquement par GET /api/subscription-plans
 * (formules actives uniquement, triées par prix croissant).
 *
 * Créée par SubscriptionPlanFixtures ou par insertion SQL au déploiement
 * (DEPLOY.md). `stripePriceId` la relie au prix Stripe à facturer. Le prix
 * est stocké en centimes (entier) pour éviter les erreurs d'arrondi.
 */
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

    /** Nom commercial affiché (sert aussi de clé d'idempotence aux fixtures). */
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

    /**
     * Nombre d'unités par période (1 = chaque mois / chaque année) ; utilisé
     * par SubscriptionService pour calculer `endsAt` (minimum 1).
     */
    #[ORM\Column(type: 'integer')]
    private int $intervalCount = 1;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $features = [];

    /** Formule en vente ; inactive, elle est masquée de la liste publique et refusée à la souscription (400). */
    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    /**
     * ID du prix Stripe (`price_...`) utilisé par StripeService pour créer la
     * session Checkout ; renseigné par les fixtures (variables STRIPE_PRICE_*)
     * ou par `app:stripe:sync-plans`. Null en mode simulé : si Stripe est
     * activé, souscrire à un plan sans ID renvoie 400.
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $stripePriceId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Mis à jour automatiquement à chaque modification (callback onPreUpdate). */
    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** Callback Doctrine (PreUpdate) : horodate chaque modification persistée. */
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

    /** Prix lisible au format français (virgule décimale, espace des milliers), ex. « 9,99 EUR ». */
    public function getFormattedPrice(): string
    {
        return number_format($this->priceCents / 100, 2, ',', ' ') . ' ' . $this->currency;
    }

    /** Périodicité normalisée pour le frontend : 'year' pour un plan annuel, 'month' dans tous les autres cas. */
    public function getBillingInterval(): string
    {
        return $this->intervalUnit === self::INTERVAL_YEAR ? 'year' : 'month';
    }

    /**
     * Sérialise la formule pour l'API publique : id, name, description,
     * priceCents, price (en unités monétaires), formattedPrice, currency,
     * intervalUnit, intervalCount, billingInterval, features, isActive, et
     * `trialDays`, toujours 0 (aucune période d'essai n'est gérée).
     * `stripePriceId` n'est pas exposé.
     *
     * @return array<string, mixed>
     */
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
