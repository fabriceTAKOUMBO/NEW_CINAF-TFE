<?php
namespace App\Entity;

use App\Repository\EpisodeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: EpisodeRepository::class)]
#[ORM\Table(name: 'episode')]
#[ORM\UniqueConstraint(name: 'uniq_episode_season_number', columns: ['season_id', 'number'])]
class Episode
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Season::class, inversedBy: 'episodes')]
    #[ORM\JoinColumn(nullable: false)]
    private Season $season;

    #[ORM\Column]
    private int $number;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $synopsis = null;

    #[ORM\Column]
    private int $duration = 0;

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
