<?php
namespace App\Entity;

use App\Repository\FeaturedContentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: FeaturedContentRepository::class)]
#[ORM\Table(name: 'featured_content')]
class FeaturedContent
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Film::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Film $film = null;

    #[ORM\ManyToOne(targetEntity: Serie::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Serie $serie = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private \DateTimeImmutable $startDate;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column]
    private bool $active = true;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->startDate = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getFilm(): ?Film { return $this->film; }
    public function setFilm(?Film $f): static { $this->film = $f; return $this; }
    public function getSerie(): ?Serie { return $this->serie; }
    public function setSerie(?Serie $s): static { $this->serie = $s; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $p): static { $this->position = $p; return $this; }
    public function getStartDate(): \DateTimeImmutable { return $this->startDate; }
    public function setStartDate(\DateTimeImmutable $d): static { $this->startDate = $d; return $this; }
    public function getEndDate(): ?\DateTimeImmutable { return $this->endDate; }
    public function setEndDate(?\DateTimeImmutable $d): static { $this->endDate = $d; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $a): static { $this->active = $a; return $this; }

    public function toArray(): array
    {
        $contentType = $this->film ? 'film' : ($this->serie ? 'serie' : null);
        $contentId = $this->film?->getId()->toRfc4122() ?? $this->serie?->getId()->toRfc4122();
        return [
            'id' => $this->id->toRfc4122(),
            'contentType' => $contentType,
            'contentId' => $contentId,
            'position' => $this->position,
            'film' => $this->film?->toArray(),
            'serie' => $this->serie?->toArray(),
        ];
    }
}
