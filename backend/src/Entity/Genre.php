<?php
namespace App\Entity;

use App\Repository\GenreRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Genre cinématographique (référentiel), rattaché aux films et séries.
 *
 * Exposé en lecture par GET /api/genres ; aucun endpoint ni fixture ne crée
 * de genre dans le code actuel. Côté inverse des ManyToMany `film_genre` /
 * `serie_genre`, portées par Film et Serie. Nom et slug sont uniques : les
 * filtres `genre` des recherches acceptent l'un ou l'autre.
 */
#[ORM\Entity(repositoryClass: GenreRepository::class)]
#[ORM\Table(name: 'genre')]
#[ORM\HasLifecycleCallbacks]
class Genre
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 100, unique: true)]
    private string $name;

    #[ORM\Column(length: 120, unique: true)]
    private string $slug;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Mis à jour automatiquement à chaque modification (callback onPreUpdate). */
    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** Côté inverse (relation portée par Film::$genres). */
    #[ORM\ManyToMany(targetEntity: Film::class, mappedBy: 'genres')]
    private Collection $films;

    /** Côté inverse (relation portée par Serie::$genres). */
    #[ORM\ManyToMany(targetEntity: Serie::class, mappedBy: 'genres')]
    private Collection $series;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->films = new ArrayCollection();
        $this->series = new ArrayCollection();
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
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): static { $this->slug = $slug; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getFilms(): Collection { return $this->films; }
    public function getSeries(): Collection { return $this->series; }

    /**
     * Sérialise le genre : id, name, slug.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'name' => $this->name,
            'slug' => $this->slug,
        ];
    }
}
