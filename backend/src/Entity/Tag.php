<?php
namespace App\Entity;

use App\Repository\TagRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Étiquette éditoriale (nom et slug uniques) rattachable aux films et séries.
 *
 * Particularité : Tag est le côté PROPRIÉTAIRE des relations `film_tag` et
 * `serie_tag` (Film::$tags et Serie::$tags en sont les côtés inverses) ;
 * c'est donc depuis Tag qu'un lien doit être créé pour être persisté.
 * Aucun endpoint, fixture ni requête n'utilise les tags dans le code actuel,
 * et Film/Serie::toArray() ne les exposent pas.
 */
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

    /** Côté propriétaire (table de jointure `film_tag`). */
    #[ORM\ManyToMany(targetEntity: Film::class, inversedBy: 'tags')]
    #[ORM\JoinTable(name: 'film_tag')]
    private Collection $films;

    /** Côté propriétaire (table de jointure `serie_tag`). */
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

    /**
     * Sérialise le tag : id, name, slug.
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
