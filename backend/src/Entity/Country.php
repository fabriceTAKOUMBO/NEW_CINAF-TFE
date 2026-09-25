<?php
namespace App\Entity;

use App\Repository\CountryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Pays (référentiel), rattaché aux films et séries comme pays de production.
 *
 * Exposé en lecture par GET /api/countries ; aucun endpoint ni fixture ne
 * crée de pays dans le code actuel. Côté inverse des ManyToMany
 * `film_country` / `serie_country`, portées par Film et Serie.
 */
#[ORM\Entity(repositoryClass: CountryRepository::class)]
#[ORM\Table(name: 'country')]
#[ORM\HasLifecycleCallbacks]
class Country
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 100)]
    private string $name;

    /**
     * Code ISO du pays (3 caractères max.), unique. Accepté, comme le nom,
     * par le filtre `country` de /api/films/search. Le PATCH admin de
     * /api/films/{id} et /api/series/{id} prévoit aussi le code ISO dans
     * `countries`, mais il appelle d'abord find(), qui lève une exception sur
     * une valeur non UUID : en pratique, seul l'UUID y fonctionne.
     */
    #[ORM\Column(length: 3, unique: true)]
    private string $isoCode;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Mis à jour automatiquement à chaque modification (callback onPreUpdate). */
    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** Côté inverse (relation portée par Film::$countries). */
    #[ORM\ManyToMany(targetEntity: Film::class, mappedBy: 'countries')]
    private Collection $films;

    /** Côté inverse (relation portée par Serie::$countries). */
    #[ORM\ManyToMany(targetEntity: Serie::class, mappedBy: 'countries')]
    private Collection $series;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->films = new ArrayCollection();
        $this->series = new ArrayCollection();
    }

    /** Callback Doctrine (PreUpdate) : horodate chaque modification persistée. */
    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getIsoCode(): string { return $this->isoCode; }
    public function setIsoCode(string $isoCode): static { $this->isoCode = $isoCode; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getFilms(): Collection { return $this->films; }
    public function getSeries(): Collection { return $this->series; }

    /**
     * Sérialise le pays : id, name, isoCode.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'name' => $this->name,
            'isoCode' => $this->isoCode,
        ];
    }
}
