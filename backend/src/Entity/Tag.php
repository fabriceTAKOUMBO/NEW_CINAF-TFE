<?php
namespace App\Entity;

use App\Repository\TagRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TagRepository::class)]
#[ORM\Table(name: 'tag')]
class Tag
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 100, unique: true)]
    private string $name;

    #[ORM\Column(length: 120, unique: true)]
    private string $slug;

    #[ORM\ManyToMany(targetEntity: Film::class, inversedBy: 'tags')]
    #[ORM\JoinTable(name: 'film_tag')]
    private Collection $films;

    #[ORM\ManyToMany(targetEntity: Serie::class, inversedBy: 'tags')]
    #[ORM\JoinTable(name: 'serie_tag')]
    private Collection $series;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->films = new ArrayCollection();
        $this->series = new ArrayCollection();
    }

    public function getId(): Uuid { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $n): static { $this->name = $n; return $this; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $s): static { $this->slug = $s; return $this; }
    public function getFilms(): Collection { return $this->films; }
    public function getSeries(): Collection { return $this->series; }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'name' => $this->name,
            'slug' => $this->slug,
        ];
    }
}
