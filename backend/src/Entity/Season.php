<?php
namespace App\Entity;

use App\Repository\SeasonRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Saison d'une Serie : regroupe des épisodes numérotés.
 *
 * Créée par l'import Bunny (un dossier de saison = une Season) ou par un
 * studio via POST /api/studio/series/{serieId}/seasons. La contrainte
 * d'unicité (serie_id, number) interdit deux saisons de même numéro dans
 * une série. Les épisodes sont en composition (cascade persist/remove +
 * orphanRemoval) et triés par numéro.
 */
#[ORM\Entity(repositoryClass: SeasonRepository::class)]
#[ORM\Table(name: 'season')]
#[ORM\UniqueConstraint(name: 'uniq_season_serie_number', columns: ['serie_id', 'number'])]
class Season
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /**
     * Série parente — côté propriétaire (FK `serie_id` NOT NULL, sans
     * ON DELETE CASCADE : la suppression passe par la cascade ORM de Serie::$seasons).
     */
    #[ORM\ManyToOne(targetEntity: Serie::class, inversedBy: 'seasons')]
    #[ORM\JoinColumn(nullable: false)]
    private Serie $serie;

    /** Numéro de la saison, unique au sein de la série (1, 2, … à l'import). */
    #[ORM\Column]
    private int $number;

    /** Titre facultatif (à l'import : nom tiré de l'arborescence Bunny). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $synopsis = null;

    /**
     * Épisodes triés par numéro. Composition : un épisode retiré de la
     * collection est supprimé au flush (orphanRemoval), et supprimer la
     * saison supprime ses épisodes (cascade ORM, pas d'ON DELETE CASCADE en base).
     */
    #[ORM\OneToMany(mappedBy: 'season', targetEntity: Episode::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['number' => 'ASC'])]
    private Collection $episodes;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->episodes = new ArrayCollection();
    }

    public function getId(): Uuid { return $this->id; }
    public function getSerie(): Serie { return $this->serie; }
    public function setSerie(Serie $s): static { $this->serie = $s; return $this; }
    public function getNumber(): int { return $this->number; }
    public function setNumber(int $n): static { $this->number = $n; return $this; }
    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $t): static { $this->title = $t; return $this; }
    public function getSynopsis(): ?string { return $this->synopsis; }
    public function setSynopsis(?string $s): static { $this->synopsis = $s; return $this; }
    public function getEpisodes(): Collection { return $this->episodes; }

    /**
     * Sérialise la saison : id, number, title, synopsis.
     *
     * @param bool $withEpisodes true : ajoute la clé `episodes` (liste des
     *                           Episode::toArray()), utilisée par la vue détail
     *                           studio via Serie::toArray($expand, $deepEpisodes).
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $withEpisodes = false): array
    {
        $base = [
            'id' => $this->id->toRfc4122(),
            'number' => $this->number,
            'title' => $this->title,
            'synopsis' => $this->synopsis,
        ];
        if ($withEpisodes) {
            $base['episodes'] = array_map(fn(Episode $e) => $e->toArray(), $this->episodes->toArray());
        }
        return $base;
    }
}
