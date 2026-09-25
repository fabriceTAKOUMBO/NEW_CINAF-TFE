<?php
namespace App\Entity;

use App\Repository\PersonRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Personne du cinéma (réalisateur ou acteur), référentiel partagé entre films.
 *
 * Le rôle n'est pas un champ : il dépend de la table de jointure utilisée,
 * `film_director` ou `film_cast` (côté propriétaire Film). Une même personne
 * peut donc réaliser un film et jouer dans un autre. Aucun lien avec les
 * séries. Exposée en lecture par GET /api/persons ; aucun endpoint ni
 * fixture ne crée de personne dans le code actuel.
 */
#[ORM\Entity(repositoryClass: PersonRepository::class)]
#[ORM\Table(name: 'person')]
#[ORM\HasLifecycleCallbacks]
class Person
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 100)]
    private string $firstName;

    #[ORM\Column(length: 100)]
    private string $lastName;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $photo = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $biography = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $birthDate = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Mis à jour automatiquement à chaque modification (callback onPreUpdate). */
    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** Films réalisés — côté inverse (relation portée par Film::$directors). */
    #[ORM\ManyToMany(targetEntity: Film::class, mappedBy: 'directors')]
    private Collection $directedFilms;

    /** Films joués — côté inverse (relation portée par Film::$cast). */
    #[ORM\ManyToMany(targetEntity: Film::class, mappedBy: 'cast')]
    private Collection $castFilms;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->directedFilms = new ArrayCollection();
        $this->castFilms = new ArrayCollection();
    }

    /** Callback Doctrine (PreUpdate) : horodate chaque modification persistée. */
    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $firstName): static { $this->firstName = $firstName; return $this; }
    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $lastName): static { $this->lastName = $lastName; return $this; }
    public function getPhoto(): ?string { return $this->photo; }
    public function setPhoto(?string $photo): static { $this->photo = $photo; return $this; }
    public function getBiography(): ?string { return $this->biography; }
    public function setBiography(?string $biography): static { $this->biography = $biography; return $this; }
    public function getBirthDate(): ?\DateTimeImmutable { return $this->birthDate; }
    public function setBirthDate(?\DateTimeImmutable $birthDate): static { $this->birthDate = $birthDate; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /**
     * Sérialise la personne : id, firstName, lastName, photo, biography,
     * birthDate (ATOM ou null). Les films associés ne sont pas inclus.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'photo' => $this->photo,
            'biography' => $this->biography,
            'birthDate' => $this->birthDate?->format(\DateTimeInterface::ATOM),
        ];
    }
}
