<?php
namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Compte utilisateur CINAF (spectateur, abonné, compte studio ou admin),
 * utilisé par le firewall JWT (fournisseur `app_user_provider`, propriété email).
 *
 * Rôles : ROLE_USER (toujours présent via getRoles()), ROLE_ABONNE,
 * ROLE_CREATEUR (compte studio), ROLE_MODERATEUR, ROLE_ADMIN. La hiérarchie
 * est définie dans security.yaml ; ROLE_ADMIN n'hérite PAS de ROLE_CREATEUR
 * (séparation stricte « Phase H »).
 *
 * Relations : studio possédé (1-1, côté inverse). Les abonnements payants
 * (Subscription) et les abonnements gratuits à des studios
 * (StudioSubscription) référencent l'utilisateur sans relation inverse ici ;
 * leurs FK sont en ON DELETE CASCADE.
 *
 * La table s'appelle "user" (mot réservé PostgreSQL), d'où le nom échappé.
 * Unicité de l'email garantie par l'index unique ; l'inscription la vérifie
 * elle-même (409), l'attribut UniqueEntity ne jouant que lors d'une validation
 * Symfony explicite de l'entité.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'Cette adresse email est déjà utilisée.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /**
     * Email unique : identifiant de connexion (getUserIdentifier()) et valeur
     * du claim `username` du JWT (le payload n'utilise pas `sub`).
     */
    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    /**
     * Rôles stockés, en colonne PostgreSQL de type `json` (et non `jsonb`) :
     * ce type refuse LIKE, d'où la pré-requête SQL native de
     * UserRepository::findPaginated() pour filtrer par rôle.
     */
    #[ORM\Column]
    private array $roles = [];

    /** Hash du mot de passe (algorithme `auto` de security.yaml), jamais le mot de passe en clair. */
    #[ORM\Column]
    private string $password;

    #[ORM\Column(length: 100)]
    private string $firstName;

    #[ORM\Column(length: 100)]
    private string $lastName;

    /**
     * Email confirmé via le lien GET /api/auth/verify-email/{token}.
     * N'est pas exigé pour se connecter.
     */
    #[ORM\Column]
    private bool $isVerified = false;

    /**
     * Compte suspendu par un admin (PATCH /api/admin/users/{id}/suspend).
     * Seul POST /api/auth/login le vérifie (403) : les JWT et refresh tokens
     * déjà émis ne sont pas révoqués par la suspension.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $isSuspended = false;

    /** Jeton aléatoire envoyé à l'inscription pour vérifier l'email ; remis à null une fois utilisé. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $verificationToken = null;

    /** Jeton du lien « mot de passe oublié » ; remis à null après réinitialisation. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $passwordResetToken = null;

    /** Fin de validité de `passwordResetToken` (émission + 1 heure). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $passwordResetTokenExpiry = null;

    /** Consentement RGPD transmis à l'inscription (null s'il n'a pas été fourni). */
    #[ORM\Column(nullable: true)]
    private ?bool $consentRgpd = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Mis à jour automatiquement à chaque modification (callback onPreUpdate). */
    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * Inverse side of Studio.owner. The FK column lives on the studio table
     * (studio.owner_id), so no extra column is added on the user table.
     * The link is created by instantiating a Studio with this user as owner.
     *
     * Null tant que l'utilisateur n'a pas de studio (avant l'onboarding).
     */
    #[ORM\OneToOne(mappedBy: 'owner', targetEntity: Studio::class)]
    private ?Studio $studio = null;

    /** Initialise l'UUID v4, les dates et le rôle de base ROLE_USER. */
    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->roles = ['ROLE_USER'];
    }

    /** Callback Doctrine (PreUpdate) : horodate chaque modification persistée. */
    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): static { $this->email = $email; return $this; }
    /** Identifiant de sécurité Symfony : l'email (aussi placé dans le claim `username` du JWT). */
    public function getUserIdentifier(): string { return $this->email; }
    /**
     * Rôles stockés + ROLE_USER, sans doublon (tout utilisateur a au moins
     * ROLE_USER). Les rôles hérités via la hiérarchie de security.yaml ne
     * sont pas ajoutés ici.
     */
    public function getRoles(): array { $roles = $this->roles; $roles[] = 'ROLE_USER'; return array_unique($roles); }
    public function setRoles(array $roles): static { $this->roles = $roles; return $this; }
    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): static { $this->password = $password; return $this; }
    /** Contrat UserInterface : rien à effacer, aucun mot de passe en clair n'est conservé sur l'entité. */
    public function eraseCredentials(): void {}
    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $firstName): static { $this->firstName = $firstName; return $this; }
    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $lastName): static { $this->lastName = $lastName; return $this; }
    public function isVerified(): bool { return $this->isVerified; }
    public function setIsVerified(bool $isVerified): static { $this->isVerified = $isVerified; return $this; }
    public function isSuspended(): bool { return $this->isSuspended; }
    public function setIsSuspended(bool $isSuspended): static { $this->isSuspended = $isSuspended; return $this; }
    public function getVerificationToken(): ?string { return $this->verificationToken; }
    public function setVerificationToken(?string $verificationToken): static { $this->verificationToken = $verificationToken; return $this; }
    public function getPasswordResetToken(): ?string { return $this->passwordResetToken; }
    public function setPasswordResetToken(?string $token): static { $this->passwordResetToken = $token; return $this; }
    public function getPasswordResetTokenExpiry(): ?\DateTimeImmutable { return $this->passwordResetTokenExpiry; }
    public function setPasswordResetTokenExpiry(?\DateTimeImmutable $expiry): static { $this->passwordResetTokenExpiry = $expiry; return $this; }
    public function getConsentRgpd(): ?bool { return $this->consentRgpd; }
    public function setConsentRgpd(?bool $consentRgpd): static { $this->consentRgpd = $consentRgpd; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getStudio(): ?Studio { return $this->studio; }

    /**
     * Représentation publique du compte, renvoyée par le login, /api/auth/me
     * et l'administration : id, email, firstName, lastName, roles (via
     * getRoles(), donc ROLE_USER inclus), createdAt (ATOM), isVerified,
     * isSuspended. N'expose jamais le hash du mot de passe ni les jetons.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'email' => $this->email,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'roles' => $this->getRoles(),
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'isVerified' => $this->isVerified,
            'isSuspended' => $this->isSuspended,
        ];
    }
}
