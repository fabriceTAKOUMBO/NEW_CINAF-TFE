<?php
namespace App\Entity;

use App\Repository\FilmRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: FilmRepository::class)]
#[ORM\Table(name: 'film')]
#[ORM\Index(name: 'idx_film_status_studio', columns: ['status', 'studio_id'])]
#[ORM\HasLifecycleCallbacks]
class Film
{
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_PUBLISHED = 'PUBLISHED';
    public const STATUS_WITHDRAWN = 'WITHDRAWN';
    /**
     * État intermédiaire : un studio non validé qui publie son premier
     * contenu envoie celui-ci en attente d'approbation administrateur.
     * Invisible publiquement (les endpoints publics filtrent positivement
     * sur PUBLISHED). Édition côté studio refusée tant que l'admin n'a pas
     * statué.
     */
    public const STATUS_PENDING_APPROVAL = 'PENDING_APPROVAL';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 280, unique: true)]
    private string $slug;

    #[ORM\Column(type: 'text')]
    private string $synopsis;

    #[ORM\Column]
    private int $year;

    #[ORM\Column]
    private int $duration;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $poster = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $trailerVideoId = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $bunnyVideoId = null;

    #[ORM\Column]
    private int $views = 0;

    #[ORM\Column(type: 'float')]
    private float $avgRating = 0.0;

    /**
     * Owning studio. Nullable in Phase A — will become NOT NULL in Phase F
     * once the import pipeline (Agent 5) has back-filled all rows.
     */
    #[ORM\ManyToOne(targetEntity: Studio::class, inversedBy: 'films')]
    #[ORM\JoinColumn(name: 'studio_id', referencedColumnName: 'id', nullable: true)]
    private ?Studio $studio = null;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_DRAFT])]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $withdrawnAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToMany(targetEntity: Genre::class, inversedBy: 'films')]
    #[ORM\JoinTable(name: 'film_genre')]
    private Collection $genres;

    #[ORM\ManyToMany(targetEntity: Country::class, inversedBy: 'films')]
    #[ORM\JoinTable(name: 'film_country')]
    private Collection $countries;

    #[ORM\ManyToMany(targetEntity: Person::class, inversedBy: 'directedFilms')]
    #[ORM\JoinTable(name: 'film_director')]
    private Collection $directors;

    #[ORM\ManyToMany(targetEntity: Person::class, inversedBy: 'castFilms')]
    #[ORM\JoinTable(name: 'film_cast')]
    private Collection $cast;

    #[ORM\ManyToMany(targetEntity: Tag::class, mappedBy: 'films')]
    private Collection $tags;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->genres = new ArrayCollection();
        $this->countries = new ArrayCollection();
        $this->directors = new ArrayCollection();
        $this->cast = new ArrayCollection();
        $this->tags = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): static { $this->slug = $slug; return $this; }
    public function getSynopsis(): string { return $this->synopsis; }
    public function setSynopsis(string $synopsis): static { $this->synopsis = $synopsis; return $this; }
    public function getYear(): int { return $this->year; }
    public function setYear(int $year): static { $this->year = $year; return $this; }
    public function getDuration(): int { return $this->duration; }
    public function setDuration(int $duration): static { $this->duration = $duration; return $this; }
    public function getPoster(): ?string { return $this->poster; }
    public function setPoster(?string $poster): static { $this->poster = $poster; return $this; }
    public function getTrailerVideoId(): ?string { return $this->trailerVideoId; }
    public function setTrailerVideoId(?string $id): static { $this->trailerVideoId = $id; return $this; }
    public function getBunnyVideoId(): ?string { return $this->bunnyVideoId; }
    public function setBunnyVideoId(?string $id): static { $this->bunnyVideoId = $id; return $this; }
    public function getViews(): int { return $this->views; }
    public function setViews(int $views): static { $this->views = $views; return $this; }
    public function incrementViews(): static { $this->views++; return $this; }
    public function getAvgRating(): float { return $this->avgRating; }
    public function setAvgRating(float $avgRating): static { $this->avgRating = $avgRating; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function getStudio(): ?Studio { return $this->studio; }
    public function setStudio(?Studio $studio): static { $this->studio = $studio; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function setPublishedAt(?\DateTimeImmutable $publishedAt): static { $this->publishedAt = $publishedAt; return $this; }
    public function getWithdrawnAt(): ?\DateTimeImmutable { return $this->withdrawnAt; }
    public function setWithdrawnAt(?\DateTimeImmutable $withdrawnAt): static { $this->withdrawnAt = $withdrawnAt; return $this; }

    public function getGenres(): Collection { return $this->genres; }
    public function addGenre(Genre $genre): static { if (!$this->genres->contains($genre)) { $this->genres->add($genre); } return $this; }
    public function removeGenre(Genre $genre): static { $this->genres->removeElement($genre); return $this; }

    public function getCountries(): Collection { return $this->countries; }
    public function addCountry(Country $country): static { if (!$this->countries->contains($country)) { $this->countries->add($country); } return $this; }
    public function removeCountry(Country $country): static { $this->countries->removeElement($country); return $this; }

    public function getDirectors(): Collection { return $this->directors; }
    public function addDirector(Person $person): static { if (!$this->directors->contains($person)) { $this->directors->add($person); } return $this; }
    public function removeDirector(Person $person): static { $this->directors->removeElement($person); return $this; }

    public function getCast(): Collection { return $this->cast; }
    public function addCastMember(Person $person): static { if (!$this->cast->contains($person)) { $this->cast->add($person); } return $this; }
    public function removeCastMember(Person $person): static { $this->cast->removeElement($person); return $this; }

    public function getTags(): Collection { return $this->tags; }

    public function toArray(bool $expand = false): array
    {
        $base = [
            'id' => $this->id->toRfc4122(),
            'title' => $this->title,
            'slug' => $this->slug,
            'synopsis' => $this->synopsis,
            'year' => $this->year,
            'duration' => $this->duration,
            'poster' => $this->poster,
            'trailerVideoId' => $this->trailerVideoId,
            'bunnyVideoId' => $this->bunnyVideoId,
            'views' => $this->views,
            'avgRating' => $this->avgRating,
            'studioId' => $this->studio?->getId()->toRfc4122(),
            'status' => $this->status,
            'publishedAt' => $this->publishedAt?->format(\DateTimeInterface::ATOM),
            'withdrawnAt' => $this->withdrawnAt?->format(\DateTimeInterface::ATOM),
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];

        if ($expand) {
            $base['genres'] = array_map(fn(Genre $g) => $g->toArray(), $this->genres->toArray());
            $base['countries'] = array_map(fn(Country $c) => $c->toArray(), $this->countries->toArray());
            $base['directors'] = array_map(fn(Person $p) => $p->toArray(), $this->directors->toArray());
            $base['cast'] = array_map(fn(Person $p) => $p->toArray(), $this->cast->toArray());
        } else {
            $base['genres'] = array_map(fn(Genre $g) => $g->getName(), $this->genres->toArray());
        }

        return $base;
    }
}
