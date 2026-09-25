<?php
namespace App\Entity;

use App\Repository\FilmRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Film du catalogue CINAF (œuvre unitaire, par opposition à une Serie).
 *
 * Deux origines possibles :
 *  - import du catalogue Bunny historique (`app:catalogue:import-bunny`) :
 *    `bunnyFolder` renseigné, statut PUBLISHED d'office, métadonnées
 *    minimales (année et durée à 0, synopsis de remplacement) ;
 *  - création par un studio (`POST /api/studio/films`) : `bunnyFolder` null,
 *    statut DRAFT au départ.
 *
 * Cycle de vie (`status`, transitions dans ContentLifecycleService) :
 * DRAFT → PUBLISHED, ou DRAFT → PENDING_APPROVAL si le studio n'est pas
 * encore validé (l'admin approuve → PUBLISHED, ou refuse → DRAFT) ;
 * PUBLISHED → WITHDRAWN après approbation admin d'une WithdrawalRequest.
 * Seuls les films PUBLISHED sont exposés par les endpoints publics.
 *
 * Relations : studio propriétaire (ManyToOne), parties vidéo FilmPart
 * (OneToMany en composition), genres / pays / réalisateurs / distribution
 * (ManyToMany dont Film est le côté propriétaire), tags (ManyToMany dont
 * Tag est le côté propriétaire). L'index composite (status, studio_id) sert
 * les listes filtrées par statut et par studio.
 */
#[ORM\Entity(repositoryClass: FilmRepository::class)]
#[ORM\Table(name: 'film')]
#[ORM\Index(name: 'idx_film_status_studio', columns: ['status', 'studio_id'])]
#[ORM\HasLifecycleCallbacks]
class Film
{
    /** Brouillon : visible uniquement par le studio propriétaire (et les admins). */
    public const STATUS_DRAFT = 'DRAFT';
    /** Publié : seul statut visible dans le catalogue public. */
    public const STATUS_PUBLISHED = 'PUBLISHED';
    /**
     * Retiré du catalogue public, en général après approbation admin d'une
     * WithdrawalRequest (un admin peut aussi l'imposer via PATCH /api/admin/films/{id}).
     */
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

    /**
     * Identifiant lisible, unique dans la table `film`. Sert aux URL du
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

    /** Durée en minutes ; 0 = inconnue (valeur posée par l'import Bunny). */
    #[ORM\Column]
    private int $duration;

    /**
     * URL publique absolue (CDN Bunny) de l'affiche — et non un chemin :
     * le frontend y enregistre l'`url` renvoyée par POST /api/studio/upload.
     */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $poster = null;

    /**
     * Malgré son nom (hérité de Bunny Stream), contient un CHEMIN Bunny
     * Storage relatif à la zone (pas une URL, pas un GUID) : celui de la
     * bande-annonce. Le catalogue public en dérive l'URL HLS
     * `{chemin}/master.m3u8`.
     */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $trailerVideoId = null;

    /**
     * Chemin Bunny Storage de la vidéo principale (même remarque de nommage
     * que `trailerVideoId`). Deux conventions coexistent :
     *  - contenu importé : chemin qui ne commence PAS par `studios/`
     *    (non modifiable côté studio, cf. StudioOwnershipChecker) ;
     *  - upload studio : `studios/{studio}/{film}/video.{ext}`.
     * Pour un film importé en plusieurs parties, pointe sur la 1re partie
     * (les suivantes sont dans `parts`).
     */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $bunnyVideoId = null;

    /**
     * Dossier racine Bunny dont l'œuvre est issue lorsqu'elle provient de
     * l'import du catalogue (ex. `FILMS/CLEOPATRA`). Reste `null` pour un film
     * créé via le module Studio. Sert de clé d'idempotence à l'import et de
     * critère de purge (le contenu studio n'est jamais touché).
     */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $bunnyFolder = null;

    /**
     * Parties vidéo du film (1 seule pour un film normal).
     *
     * Renseignées uniquement par l'import Bunny : un film créé via le Studio
     * n'en a aucune, sa vidéo est alors portée par `bunnyVideoId`.
     * Composition : cascade persist/remove + orphanRemoval (une partie
     * retirée de la collection est supprimée en base au flush suivant).
     */
    #[ORM\OneToMany(mappedBy: 'film', targetEntity: FilmPart::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['number' => 'ASC'])]
    private Collection $parts;

    /** Compteur de lectures (POST /api/films/{id}/view) ; sert au tri de /api/films/trending. */
    #[ORM\Column]
    private int $views = 0;

    /** Note moyenne : aucun code ne la met à jour actuellement (reste à 0.0). */
    #[ORM\Column(type: 'float')]
    private float $avgRating = 0.0;

    /**
     * Owning studio. Nullable in Phase A — will become NOT NULL in Phase F
     * once the import pipeline (Agent 5) has back-filled all rows.
     *
     * État actuel : la migration Phase F (Version20260430200000) a bien passé
     * la colonne `film.studio_id` en NOT NULL en base, mais le mapping est
     * resté `nullable: true`. Conséquence connue : `doctrine:migrations:diff`
     * propose un `DROP NOT NULL` parasite, à retirer à la main (gotcha #16
     * de BACKEND_MEMORY). Côté propriétaire de la relation (FK sur `film`).
     */
    #[ORM\ManyToOne(targetEntity: Studio::class, inversedBy: 'films')]
    #[ORM\JoinColumn(name: 'studio_id', referencedColumnName: 'id', nullable: true)]
    private ?Studio $studio = null;

    /**
     * Statut du cycle de vie : une des constantes STATUS_* (DRAFT par défaut).
     * Les transitions passent par ContentLifecycleService ; l'admin peut aussi
     * forcer un statut via PATCH /api/admin/films/{id}.
     */
    #[ORM\Column(length: 20, options: ['default' => self::STATUS_DRAFT])]
    private string $status = self::STATUS_DRAFT;

    /** Date du passage à PUBLISHED ; null tant que le film n'a jamais été publié. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    /** Date du passage à WITHDRAWN ; null tant que le film n'a jamais été retiré. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $withdrawnAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Mis à jour automatiquement à chaque modification (callback onPreUpdate). */
    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** Côté propriétaire (table de jointure `film_genre`). */
    #[ORM\ManyToMany(targetEntity: Genre::class, inversedBy: 'films')]
    #[ORM\JoinTable(name: 'film_genre')]
    private Collection $genres;

    /** Pays de production — côté propriétaire (table `film_country`). */
    #[ORM\ManyToMany(targetEntity: Country::class, inversedBy: 'films')]
    #[ORM\JoinTable(name: 'film_country')]
    private Collection $countries;

    /** Réalisateurs — côté propriétaire (table `film_director`). */
    #[ORM\ManyToMany(targetEntity: Person::class, inversedBy: 'directedFilms')]
    #[ORM\JoinTable(name: 'film_director')]
    private Collection $directors;

    /** Distribution (acteurs) — côté propriétaire (table `film_cast`). */
    #[ORM\ManyToMany(targetEntity: Person::class, inversedBy: 'castFilms')]
    #[ORM\JoinTable(name: 'film_cast')]
    private Collection $cast;

    /**
     * Côté inverse : la relation est portée par Tag::$films (table `film_tag`).
     * C'est pourquoi Film n'expose pas d'addTag() : toute modification doit
     * passer par l'entité Tag pour être persistée.
     */
    #[ORM\ManyToMany(targetEntity: Tag::class, mappedBy: 'films')]
    private Collection $tags;

    /** Initialise l'UUID v4, les dates de création/modification et les collections vides. */
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
        $this->parts = new ArrayCollection();
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
    public function getDuration(): int { return $this->duration; }
    public function setDuration(int $duration): static { $this->duration = $duration; return $this; }
    public function getPoster(): ?string { return $this->poster; }
    public function setPoster(?string $poster): static { $this->poster = $poster; return $this; }
    public function getTrailerVideoId(): ?string { return $this->trailerVideoId; }
    public function setTrailerVideoId(?string $id): static { $this->trailerVideoId = $id; return $this; }
    public function getBunnyVideoId(): ?string { return $this->bunnyVideoId; }
    public function setBunnyVideoId(?string $id): static { $this->bunnyVideoId = $id; return $this; }
    public function getBunnyFolder(): ?string { return $this->bunnyFolder; }
    public function setBunnyFolder(?string $folder): static { $this->bunnyFolder = $folder; return $this; }

    public function getParts(): Collection { return $this->parts; }
    /**
     * Ajoute une partie (sans doublon) et renseigne son côté propriétaire
     * (FilmPart::film), indispensable pour que Doctrine écrive `film_id`.
     */
    public function addPart(FilmPart $part): static
    {
        if (!$this->parts->contains($part)) {
            $this->parts->add($part);
            $part->setFilm($this);
        }
        return $this;
    }
    /** Retire une partie ; orphanRemoval la supprime alors en base au prochain flush. */
    public function removePart(FilmPart $part): static { $this->parts->removeElement($part); return $this; }
    public function getViews(): int { return $this->views; }
    public function setViews(int $views): static { $this->views = $views; return $this; }
    /** Incrémente le compteur de vues en mémoire (persisté au flush par l'appelant). */
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

    /**
     * Sérialise le film pour les réponses JSON de l'API (catalogue public
     * /api/films, module Studio, module Admin).
     *
     * Clés toujours présentes : id, title, slug, synopsis, year, duration,
     * poster, trailerVideoId, bunnyVideoId, views, avgRating, studioId
     * (UUID ou null), status, publishedAt, withdrawnAt, createdAt (dates au
     * format ATOM ou null). `bunnyFolder`, `parts` et `tags` ne sont pas exposés.
     *
     * @param bool $expand true : genres, countries, directors et cast sous forme
     *                     d'objets complets ; false : seulement `genres`, réduit
     *                     à la liste des noms.
     *
     * @return array<string, mixed>
     */
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
