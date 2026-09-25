<?php
namespace App\Entity;

use App\Repository\EpisodeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Épisode d'une saison de série : c'est lui qui porte la vidéo à lire.
 *
 * Créé par l'import Bunny ou par un studio via
 * POST /api/studio/series/{serieId}/seasons/{seasonId}/episodes. La
 * contrainte d'unicité (season_id, number) interdit deux épisodes de même
 * numéro dans une saison. Pas de statut propre : un épisode n'est exposé
 * publiquement que si sa série parente est PUBLISHED (cf. EpisodeController).
 * Pas d'affiche non plus (aucune colonne `poster`, dette connue).
 */
#[ORM\Entity(repositoryClass: EpisodeRepository::class)]
#[ORM\Table(name: 'episode')]
#[ORM\UniqueConstraint(name: 'uniq_episode_season_number', columns: ['season_id', 'number'])]
class Episode
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /** Saison parente — côté propriétaire (FK `season_id` NOT NULL). */
    #[ORM\ManyToOne(targetEntity: Season::class, inversedBy: 'episodes')]
    #[ORM\JoinColumn(nullable: false)]
    private Season $season;

    /**
     * Numéro de l'épisode, unique dans la saison. À l'import, il suit l'ordre
     * des dossiers Bunny, qui n'est pas toujours l'ordre réel : le catalogue
     * public relit alors le numéro présent dans le titre (« EP 4 »…).
     */
    #[ORM\Column]
    private int $number;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $synopsis = null;

    /** Durée en minutes ; 0 = inconnue (cas des épisodes importés). */
    #[ORM\Column]
    private int $duration = 0;

    /**
     * Chemin Bunny Storage de la vidéo (pas une URL ni un GUID malgré le nom) :
     * chemin importé (ne commence pas par `studios/`, ex. `12_CAS/CAS_1/CAS1_E01`)
     * ou upload studio `studios/{studio}/{serie}/saison-{N}/episode-{NN}/video.{ext}`.
     * Le catalogue public en dérive l'URL HLS `{chemin}/master.m3u8`.
     */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $bunnyVideoId = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid { return $this->id; }
    public function getSeason(): Season { return $this->season; }
    public function setSeason(Season $s): static { $this->season = $s; return $this; }
    public function getNumber(): int { return $this->number; }
    public function setNumber(int $n): static { $this->number = $n; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $t): static { $this->title = $t; return $this; }
    public function getSynopsis(): ?string { return $this->synopsis; }
    public function setSynopsis(?string $s): static { $this->synopsis = $s; return $this; }
    public function getDuration(): int { return $this->duration; }
    public function setDuration(int $d): static { $this->duration = $d; return $this; }
    public function getBunnyVideoId(): ?string { return $this->bunnyVideoId; }
    public function setBunnyVideoId(?string $id): static { $this->bunnyVideoId = $id; return $this; }

    /**
     * Sérialise l'épisode pour l'API : id, number, title, synopsis, duration,
     * bunnyVideoId (la saison et la série parentes ne sont pas incluses).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'number' => $this->number,
            'title' => $this->title,
            'synopsis' => $this->synopsis,
            'duration' => $this->duration,
            'bunnyVideoId' => $this->bunnyVideoId,
        ];
    }
}
