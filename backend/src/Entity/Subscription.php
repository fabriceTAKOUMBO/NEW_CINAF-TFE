<?php
namespace App\Entity;

use App\Repository\SubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Abonnement PAYANT d'un utilisateur à la plateforme CINAF — à ne pas
 * confondre avec StudioSubscription (suivi gratuit d'un studio).
 *
 * Conditionne la lecture des vidéos (`hasActiveSubscription` de
 * /api/auth/me, contrôle can-play du catalogue). Créé et mis à jour par
 * SubscriptionService : activation immédiate en mode simulé
 * (STRIPE_ENABLED=false), ou création au webhook Stripe
 * `checkout.session.completed` puis synchronisation par les webhooks suivants.
 *
 * Un utilisateur n'a normalement qu'un abonnement ACTIVE à la fois : souscrire
 * à un nouveau plan passe le précédent en CANCELED. L'historique est conservé
 * (les lignes remplacées ne sont pas supprimées). L'index (user_id, status)
 * accélère la recherche de l'abonnement courant.
 */
#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
#[ORM\Table(name: 'subscription')]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['user_id', 'status'], name: 'idx_subscription_user_status')]
class Subscription
{
    /**
     * En cours. Reste ACTIVE pendant une résiliation différée (canceledAt
     * renseigné, accès conservé jusqu'à endsAt).
     */
    public const STATUS_ACTIVE = 'ACTIVE';
    /**
     * Annulé : remplacé par un nouveau plan, annulation immédiate (usage
     * historique) ou statut Stripe canceled / incomplete_expired.
     */
    public const STATUS_CANCELED = 'CANCELED';
    /**
     * Expiré : fin de période signalée par Stripe (customer.subscription.deleted)
     * ou statut Stripe past_due / unpaid (et tout statut non reconnu).
     */
    public const STATUS_EXPIRED = 'EXPIRED';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /** Abonné — FK en ON DELETE CASCADE : supprimer le compte supprime ses abonnements. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Formule souscrite — FK sans cascade : un plan encore référencé ne peut pas être supprimé. */
    #[ORM\ManyToOne(targetEntity: SubscriptionPlan::class)]
    #[ORM\JoinColumn(nullable: false)]
    private SubscriptionPlan $plan;

    /** Une des constantes STATUS_* (ACTIVE par défaut). */
    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_ACTIVE;

    /** Début de la période d'abonnement. */
    #[ORM\Column]
    private \DateTimeImmutable $startsAt;

    /**
     * Fin de la période payée : calculée depuis le plan (mode simulé) ou
     * reprise de `current_period_end` (webhooks Stripe). Null = sans échéance.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    /**
     * Date de résiliation. Renseignée alors que le statut reste ACTIVE, elle
     * signale une résiliation différée (accès conservé jusqu'à endsAt).
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $canceledAt = null;

    /** Identifiant de l'abonnement Stripe ; clé de réconciliation des webhooks (null en mode simulé). */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $stripeSubscriptionId = null;

    /** Identifiant du client Stripe, réutilisé lors d'un nouveau Checkout pour garder l'historique Stripe. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Mis à jour automatiquement à chaque modification (callback onPreUpdate). */
    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** Initialise l'UUID v4 et les dates (startsAt = maintenant, modifiable ensuite). */
    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->startsAt = new \DateTimeImmutable();
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
     *
     * Concrètement : statut ACTIVE et `endsAt` null ou dans le futur. Une
     * résiliation différée (canceledAt renseigné) reste donc active jusqu'à
     * `endsAt` ; c'est cette méthode qui décide de l'accès à la lecture.
     */
    public function isCurrentlyActive(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }
        return $this->endsAt === null || $this->endsAt > new \DateTimeImmutable();
    }

    /**
     * Sérialise l'abonnement pour l'API : id, status, startsAt, endsAt,
     * canceledAt (ATOM ou null), isCurrentlyActive, et `plan`, un résumé du
     * plan (id, name, prix en centimes / euros / formaté, devise, intervalle,
     * features). Les identifiants Stripe ne sont pas exposés.
     *
     * @return array<string, mixed>
     */
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
