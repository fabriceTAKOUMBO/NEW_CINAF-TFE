<?php
namespace App\Entity;

use App\Repository\FeaturedContentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Mise en avant éditoriale (« à la une ») d'un film OU d'une série, sur une
 * période donnée.
 *
 * Un seul des deux champs `film` / `serie` est censé être renseigné (rien ne
 * l'impose en base). Les deux FK sont en ON DELETE CASCADE : supprimer
 * l'œuvre supprime sa mise en avant. Lue par FeaturedContentRepository::findActive()
 * pour GET /api/films/featured, qui ne renvoie que les films PUBLISHED (les
 * séries mises en avant y sont ignorées). Aucun endpoint ni fixture ne crée
 * de mise en avant dans le code actuel.
 */
#[ORM\Entity(repositoryClass: FeaturedContentRepository::class)]
#[ORM\Table(name: 'featured_content')]
class FeaturedContent
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /** Film mis en avant (relation unidirectionnelle) ; null si c'est une série. */
    #[ORM\ManyToOne(targetEntity: Film::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Film $film = null;

    /** Série mise en avant (relation unidirectionnelle) ; null si c'est un film. */
    #[ORM\ManyToOne(targetEntity: Serie::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Serie $serie = null;

    /** Ordre d'affichage (tri croissant). */
    #[ORM\Column]
    private int $position = 0;

    /** Début de la mise en avant (maintenant par défaut). */
    #[ORM\Column]
    private \DateTimeImmutable $startDate;

    /** Fin de la mise en avant ; null = sans date de fin. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endDate = null;

    /** Interrupteur manuel : false masque la mise en avant quelle que soit la période. */
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

    /**
     * Sérialise la mise en avant : id, contentType ('film', 'serie' ou null
     * si aucune œuvre), contentId, position, et l'œuvre elle-même sous `film`
     * ou `serie` (toArray() non étendu, l'autre clé valant null).
     * Non utilisée par /api/films/featured, qui renvoie directement les films.
     *
     * @return array<string, mixed>
     */
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
