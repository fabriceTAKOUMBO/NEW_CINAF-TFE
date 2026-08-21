<?php
namespace App\Entity;

use App\Repository\SerieRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SerieRepository::class)]
#[ORM\Table(name: 'serie')]
#[ORM\Index(name: 'idx_serie_status_studio', columns: ['status', 'studio_id'])]
#[ORM\HasLifecycleCallbacks]
class Serie
{
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_PUBLISHED = 'PUBLISHED';
    public const STATUS_WITHDRAWN = 'WITHDRAWN';
    /**
     * État intermédiaire : un studio non validé qui publie sa première
     * série l'envoie en attente d'approbation administrateur. Invisible
     * publiquement, édition refusée côté studio tant que l'admin n'a pas
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

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $poster = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $trailerVideoId = null;

    /**
     * Dossier racine Bunny dont l'œuvre est issue lorsqu'elle provient de
     * l'import du catalogue (ex. `MADAME_SALVADOR`). Reste `null` pour une
     * série créée via le module Studio. Sert de clé d'idempotence à l'import
     * et de critère de purge — remplace le détournement de `trailerVideoId`
     * qui servait auparavant à stocker ce chemin.
     */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $bunnyFolder = null;

    #[ORM\Column]
    private int $nbSeasons = 0;

    /**
     * Owning studio. Nullable in Phase A — will become NOT NULL in Phase F
     * once the import pipeline (Agent 5) has back-filled all rows.
     */
    #[ORM\ManyToOne(targetEntity: Studio::class, inversedBy: 'series')]
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

    #[ORM\ManyToMany(targetEntity: Genre::class, inversedBy: 'series')]
    #[ORM\JoinTable(name: 'serie_genre')]
    private Collection $genres;

    #[ORM\ManyToMany(targetEntity: Country::class, inversedBy: 'series')]
    #[ORM\JoinTable(name: 'serie_country')]
    private Collection $countries;

    #[ORM\OneToMany(mappedBy: 'serie', targetEntity: Season::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['number' => 'ASC'])]
    private Collection $seasons;

    #[ORM\ManyToMany(targetEntity: Tag::class, mappedBy: 'series')]
    private Collection $tags;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->genres = new ArrayCollection();
        $this->countries = new ArrayCollection();
        $this->seasons = new ArrayCollection();
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
    public function getPoster(): ?string { return $this->poster; }
    public function setPoster(?string $poster): static { $this->poster = $poster; return $this; }
    public function getTrailerVideoId(): ?string { return $this->trailerVideoId; }
    public function setTrailerVideoId(?string $id): static { $this->trailerVideoId = $id; return $this; }
    public function getBunnyFolder(): ?string { return $this->bunnyFolder; }
    public function setBunnyFolder(?string $folder): static { $this->bunnyFolder = $folder; return $this; }
    public function getNbSeasons(): int { return $this->nbSeasons; }
    public function setNbSeasons(int $nb): static { $this->nbSeasons = $nb; return $this; }
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
    public function addGenre(Genre $g): static { if (!$this->genres->contains($g)) { $this->genres->add($g); } return $this; }
    public function removeGenre(Genre $g): static { $this->genres->removeElement($g); return $this; }
    public function getCountries(): Collection { return $this->countries; }
    public function addCountry(Country $c): static { if (!$this->countries->contains($c)) { $this->countries->add($c); } return $this; }
    public function removeCountry(Country $c): static { $this->countries->removeElement($c); return $this; }
    public function getSeasons(): Collection { return $this->seasons; }
    public function getTags(): Collection { return $this->tags; }

    /**
     * @param bool $expand        Inclut genres/countries/seasons en plus du résumé.
     * @param bool $deepEpisodes  Si true ET $expand=true, les saisons exposent
     *                            aussi leurs épisodes. Utilisé par la vue détail
     *                            (`GET /api/studio/series/{id}`) pour permettre
     *                            au frontend de lister `season.episodes`.
     *                            Laissé à false pour la liste paginée (payload).
     */
    public function toArray(bool $expand = false, bool $deepEpisodes = false): array
    {
        $base = [
            'id' => $this->id->toRfc4122(),
            'title' => $this->title,
            'slug' => $this->slug,
            'synopsis' => $this->synopsis,
            'year' => $this->year,
            'poster' => $this->poster,
            'trailerVideoId' => $this->trailerVideoId,
            'nbSeasons' => $this->nbSeasons,
            'studioId' => $this->studio?->getId()->toRfc4122(),
            'status' => $this->status,
            'publishedAt' => $this->publishedAt?->format(\DateTimeInterface::ATOM),
            'withdrawnAt' => $this->withdrawnAt?->format(\DateTimeInterface::ATOM),
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
        if ($expand) {
            $base['genres'] = array_map(fn(Genre $g) => $g->toArray(), $this->genres->toArray());
            $base['countries'] = array_map(fn(Country $c) => $c->toArray(), $this->countries->toArray());
            $base['seasons'] = array_map(
                fn(Season $s) => $s->toArray($deepEpisodes),
                $this->seasons->toArray(),
            );
        } else {
            $base['genres'] = array_map(fn(Genre $g) => $g->getName(), $this->genres->toArray());
        }
        return $base;
    }
}
