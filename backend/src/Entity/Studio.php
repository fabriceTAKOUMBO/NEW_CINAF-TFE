<?php
namespace App\Entity;

use App\Repository\StudioRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: StudioRepository::class)]
#[ORM\Table(name: 'studio')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['name'], message: 'Ce nom de studio est déjà utilisé.')]
#[UniqueEntity(fields: ['slug'], message: 'Ce slug est déjà utilisé.')]
#[UniqueEntity(fields: ['bunnyFolder'], message: 'Ce dossier Bunny est déjà utilisé.')]
class Studio
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 255, unique: true)]
    private string $name;

    #[ORM\Column(length: 280, unique: true)]
    private string $slug;

    /**
     * Owner user (must hold ROLE_CREATEUR). FK with ON DELETE RESTRICT
     * to prevent deleting a user that still owns a studio.
     *
     * One-to-one: a single user owns at most one studio (and vice-versa).
     */
    #[ORM\OneToOne(inversedBy: 'studio', targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $owner;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $logoUrl = null;

    /**
     * Root folder on the Bunny Storage zone dedicated to this studio
     * (e.g. "studios/nollywood-studios/"). Unique across the platform.
     */
    #[ORM\Column(length: 255, unique: true)]
    private string $bunnyFolder;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    /**
     * Indique si le studio a déjà eu un premier contenu approuvé par
     * un administrateur. Un studio créé en self-service par un utilisateur
     * démarre à `false` : son premier publish de film/série passe alors par
     * un état `PENDING_APPROVAL` au lieu de `PUBLISHED`. Une fois un premier
     * contenu approuvé, le flag bascule à `true` et les publish suivants
     * deviennent directs (comportement historique).
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $isValidated = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, Film>
     */
    #[ORM\OneToMany(mappedBy: 'studio', targetEntity: Film::class)]
    private Collection $films;

    /**
     * @var Collection<int, Serie>
     */
    #[ORM\OneToMany(mappedBy: 'studio', targetEntity: Serie::class)]
    private Collection $series;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->films = new ArrayCollection();
        $this->series = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): static { $this->slug = $slug; return $this; }
    public function getOwner(): User { return $this->owner; }
    public function setOwner(User $owner): static { $this->owner = $owner; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
    public function getLogoUrl(): ?string { return $this->logoUrl; }
    public function setLogoUrl(?string $logoUrl): static { $this->logoUrl = $logoUrl; return $this; }
    public function getBunnyFolder(): string { return $this->bunnyFolder; }
    public function setBunnyFolder(string $bunnyFolder): static { $this->bunnyFolder = $bunnyFolder; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): static { $this->isActive = $isActive; return $this; }
    public function isValidated(): bool { return $this->isValidated; }
    public function setIsValidated(bool $isValidated): static { $this->isValidated = $isValidated; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function getFilms(): Collection { return $this->films; }
    public function getSeries(): Collection { return $this->series; }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'logoUrl' => $this->logoUrl,
            'bunnyFolder' => $this->bunnyFolder,
            'isActive' => $this->isActive,
            'isValidated' => $this->isValidated,
            'ownerId' => $this->owner->getId()->toRfc4122(),
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt' => $this->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Représentation publique du studio (vue « chaîne YouTube » côté public).
     *
     * Reprend les champs de `toArray()` MAIS retire `ownerId` (donnée privée
     * — un visiteur non authentifié n'a pas à connaître l'utilisateur
     * propriétaire) et ajoute les compteurs de contenus publiés + le compteur
     * d'abonnés (sémantique « subscribers YouTube »).
     *
     * Les compteurs sont injectés depuis l'extérieur (par le contrôleur, qui
     * appelle `FilmRepository::countByStudioAndStatus()` /
     * `SerieRepository::countByStudioAndStatus()` /
     * `StudioSubscriptionRepository::countByStudio()`) afin de garder l'entité
     * découplée des repositories.
     *
     * `subscribersCount` est optionnel (défaut 0) pour préserver la compat
     * ascendante avec les appelants existants qui n'ont pas encore été
     * migrés vers la nouvelle signature.
     */
    public function toPublicArray(int $publishedFilmsCount, int $publishedSeriesCount, int $subscribersCount = 0): array
    {
        $base = $this->toArray();
        // Donnée privée : on ne l'expose pas sur l'endpoint public.
        unset($base['ownerId']);
        $base['publishedFilmsCount'] = $publishedFilmsCount;
        $base['publishedSeriesCount'] = $publishedSeriesCount;
        $base['subscribersCount'] = $subscribersCount;
        return $base;
    }
}
