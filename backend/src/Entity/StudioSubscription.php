<?php
namespace App\Entity;

use App\Repository\StudioSubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Abonnement « gratuit » d'un utilisateur authentifié à un studio
 * (sémantique « follow YouTube »).
 *
 * Cette entité de liaison n'a rien à voir avec l'entité Subscription
 * (abonnement payant CINAF lié à Stripe). Elle modélise uniquement le
 * fait qu'un utilisateur « suit » un studio public, dans le but :
 *  - d'alimenter un compteur public `subscribersCount` sur la fiche
 *    studio (vue chaîne YouTube côté visiteur) ;
 *  - de permettre au studio de voir, depuis son dashboard, le nombre
 *    d'utilisateurs qui suivent sa chaîne ;
 *  - de servir de base à une future feed personnalisée des publications
 *    des studios suivis.
 *
 * Contrainte d'unicité (user_id, studio_id) : un utilisateur ne peut être
 * abonné qu'une seule fois au même studio. L'opération est néanmoins
 * réversible : un user peut se désabonner puis se réabonner librement.
 *
 * Suppression en cascade côté User et côté Studio : si l'une ou l'autre
 * des deux entités liées est supprimée, la ligne d'abonnement disparaît
 * automatiquement (aucun intérêt fonctionnel à conserver l'historique).
 */
#[ORM\Entity(repositoryClass: StudioSubscriptionRepository::class)]
#[ORM\Table(name: 'studio_subscription')]
#[ORM\UniqueConstraint(name: 'uniq_user_studio', columns: ['user_id', 'studio_id'])]
#[ORM\Index(name: 'idx_subscription_studio', columns: ['studio_id'])]
class StudioSubscription
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /**
     * Utilisateur abonné. La FK est posée en ON DELETE CASCADE pour que la
     * suppression d'un user RGPD purge automatiquement ses abonnements.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * Studio suivi. ON DELETE CASCADE également : la suppression d'un studio
     * (cas marginal, généralement on désactive plutôt qu'on supprime) nettoie
     * la table d'abonnements correspondante.
     */
    #[ORM\ManyToOne(targetEntity: Studio::class)]
    #[ORM\JoinColumn(name: 'studio_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Studio $studio;

    /**
     * Date à laquelle l'abonnement a été créé. Recalculée à chaque
     * `subscribe` : si l'utilisateur s'est désabonné puis réabonné, on
     * dispose d'une nouvelle ligne avec une nouvelle date (l'ancienne a
     * été supprimée lors du désabonnement).
     */
    #[ORM\Column]
    private \DateTimeImmutable $subscribedAt;

    public function __construct(User $user, Studio $studio)
    {
        $this->id = Uuid::v4();
        $this->user = $user;
        $this->studio = $studio;
        $this->subscribedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getStudio(): Studio
    {
        return $this->studio;
    }

    public function getSubscribedAt(): \DateTimeImmutable
    {
        return $this->subscribedAt;
    }
}
