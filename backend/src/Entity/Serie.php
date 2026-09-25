<?php
namespace App\Entity;

use App\Repository\SerieRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Série du catalogue CINAF : œuvre découpée en saisons (Season), elles-mêmes
 * découpées en épisodes (Episode).
 *
 * Mêmes origines et même cycle de vie que Film : import Bunny (publiée
 * d'office, `bunnyFolder` renseigné) ou création DRAFT par un studio
 * (`POST /api/studio/series`) ; DRAFT → PUBLISHED ou PENDING_APPROVAL →
 * WITHDRAWN, transitions dans ContentLifecycleService. Seules les séries
 * PUBLISHED sont exposées publiquement, et leurs épisodes héritent de cette
 * visibilité (cf. EpisodeController).
 *
 * Contrairement à Film, la vidéo n'est pas portée par la série mais par
 * chaque épisode (`Episode::bunnyVideoId`).
 *
 * Relations : studio propriétaire (ManyToOne), saisons (OneToMany en
 * composition), genres et pays (ManyToMany, Serie côté propriétaire), tags
 * (côté inverse, relation portée par Tag).
 */
#[ORM\Entity(repositoryClass: SerieRepository::class)]
#[ORM\Table(name: 'serie')]
#[ORM\Index(name: 'idx_serie_status_studio', columns: ['status', 'studio_id'])]
#[ORM\HasLifecycleCallbacks]
class Serie
{
    /** Brouillon : visible uniquement par le studio propriétaire (et les admins). */
    public const STATUS_DRAFT = 'DRAFT';
    /** Publiée : seul statut visible dans le catalogue public. */
    public const STATUS_PUBLISHED = 'PUBLISHED';
    /**
     * Retirée du catalogue public, en général après approbation admin d'une
     * WithdrawalRequest (un admin peut aussi l'imposer via PATCH /api/admin/series/{id}).
     */
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

    /**
     * Identifiant lisible, unique dans la table `serie`. Sert aux URL du
     * catalogue public et au chemin Bunny des uploads studio
     * (`studios/{studio}/{slug}/...`, cf. BunnyPathBuilder).
     */
    #[ORM\Column(length: 280, unique: true)]
    private string $slug;

    /**
     * Texte libre. Les œuvres importées reçoivent un texte de remplacement
     * technique, masqué par le catalogue public (CatalogueDiscoverController).
     */
    #[ORM\Column(type: 'text')]
    private string $synopsis;

    /** Année de sortie ; 0 = inconnue (valeur posée par l'import Bunny). */
    #[ORM\Column]
    private int $year;

    /** URL publique absolue (CDN Bunny) de l'affiche, pas un chemin. */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $poster = null;

    /**
     * Malgré son nom (hérité de Bunny Stream), contient un CHEMIN Bunny
     * Storage relatif à la zone (pas une URL) : celui de la bande-annonce,
     * dont le catalogue public dérive l'URL HLS `{chemin}/master.m3u8`.
     */
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

    /**
     * Nombre de saisons, stocké (dénormalisé). Seul l'import Bunny le
     * renseigne : il n'est pas recalculé quand un studio ajoute ou supprime
     * des saisons (le catalogue public compte, lui, les saisons réelles).
     */
    #[ORM\Column]
    private int $nbSeasons = 0;

    /**
     * Owning studio. Nullable in Phase A — will become NOT NULL in Phase F
     * once the import pipeline (Agent 5) has back-filled all rows.
     *
     * État actuel : la migration Phase F (Version20260430200000) a bien passé
     * la colonne `serie.studio_id` en NOT NULL en base, mais le mapping est
     * resté `nullable: true`. Conséquence connue : `doctrine:migrations:diff`
     * propose un `DROP NOT NULL` parasite, à retirer à la main (gotcha #16
     * de BACKEND_MEMORY). Côté propriétaire de la relation (FK sur `serie`).
     */
    #[ORM\ManyToOne(targetEntity: Studio::class, inversedBy: 'series')]
    #[ORM\JoinColumn(name: 'studio_id', referencedColumnName: 'id', nullable: true)]
    private ?Studio $studio = null;

    /**
     * Statut du cycle de vie : une des constantes STATUS_* (DRAFT par défaut).
     * Les transitions passent par ContentLifecycleService ; l'admin peut aussi
     * forcer un statut via PATCH /api/admin/series/{id}.
     */
    #[ORM\Column(length: 20, options: ['default' => self::STATUS_DRAFT])]
    private string $status = self::STATUS_DRAFT;

    /** Date du passage à PUBLISHED ; null tant que la série n'a jamais été publiée. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    /** Date du passage à WITHDRAWN ; null tant que la série n'a jamais été retirée. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $withdrawnAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Mis à jour automatiquement à chaque modification (callback onPreUpdate). */
    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** Côté propriétaire (table de jointure `serie_genre`). */
    #[ORM\ManyToMany(targetEntity: Genre::class, inversedBy: 'series')]
    #[ORM\JoinTable(name: 'serie_genre')]
    private Collection $genres;

    /** Pays de production — côté propriétaire (table `serie_country`). */
    #[ORM\ManyToMany(targetEntity: Country::class, inversedBy: 'series')]
    #[ORM\JoinTable(name: 'serie_country')]
    private Collection $countries;

    /**
     * Saisons triées par numéro. Composition : cascade persist/remove +
     * orphanRemoval (supprimer la série supprime ses saisons, et donc leurs
     * épisodes, au niveau ORM — la FK `season.serie_id` n'a pas d'ON DELETE CASCADE).
     */
    #[ORM\OneToMany(mappedBy: 'serie', targetEntity: Season::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['number' => 'ASC'])]
    private Collection $seasons;

    /** Côté inverse : relation portée par Tag::$series (table `serie_tag`). */
    #[ORM\ManyToMany(targetEntity: Tag::class, mappedBy: 'series')]
    private Collection $tags;

    /** Initialise l'UUID v4, les dates de création/modification et les collections vides. */
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

    /** Callback Doctrine (PreUpdate) : horodate chaque modification persistée. */
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
     * Sérialise la série pour les réponses JSON de l'API (catalogue public
     * /api/series, module Studio, module Admin).
     *
     * Clés toujours présentes : id, title, slug, synopsis, year, poster,
     * trailerVideoId, nbSeasons, studioId (UUID ou null), status, publishedAt,
     * withdrawnAt, createdAt (dates au format ATOM ou null). Sans $expand,
     * `genres` est réduit à la liste des noms. `bunnyFolder` n'est pas exposé.
     *
     * @param bool $expand        Inclut genres/countries/seasons en plus du résumé.
     * @param bool $deepEpisodes  Si true ET $expand=true, les saisons exposent
     *                            aussi leurs épisodes. Utilisé par la vue détail
     *                            (`GET /api/studio/series/{id}`) pour permettre
     *                            au frontend de lister `season.episodes`.
     *                            Laissé à false pour la liste paginée (payload).
     *
     * @return array<string, mixed>
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
