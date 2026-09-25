<?php
namespace App\Entity;

use App\Repository\LanguageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Langue (référentiel), exposée en lecture par GET /api/languages.
 *
 * Aucune relation avec Film ni Serie dans le mapping actuel : le paramètre
 * `lang` lu par GET /api/films/search n'a donc aucun effet sur la recherche.
 * Aucun endpoint ni fixture ne crée de langue dans le code actuel.
 */
#[ORM\Entity(repositoryClass: LanguageRepository::class)]
#[ORM\Table(name: 'language')]
#[ORM\HasLifecycleCallbacks]
class Language
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 100)]
    private string $name;

    /** Code de la langue (5 caractères max.), unique. */
    #[ORM\Column(length: 5, unique: true)]
    private string $isoCode;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Mis à jour automatiquement à chaque modification (callback onPreUpdate). */
    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
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

    /**
     * Sérialise la langue : id, name, isoCode.
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
