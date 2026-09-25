<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Partie d'un film découpé en plusieurs fichiers vidéo sur Bunny.
 *
 * Le catalogue historique contient des films livrés en plusieurs morceaux
 * (ex. `FILMS/GUCCI_BROTHERS/PART1|PART2|PART3`, `FILMS/LIMPASSE/PARTIE_1|PARTIE_2`).
 * Un film « normal » n'a qu'une seule partie ; l'entité reste donc valable
 * dans les deux cas et évite de dupliquer le film en autant d'entrées.
 *
 * Les parties sont ordonnées par `number` (1-based) et portent chacune leur
 * propre chemin Bunny, d'où est construite l'URL HLS de lecture.
 *
 * Seule la commande d'import `app:catalogue:import-bunny` crée des parties :
 * un film créé via le module Studio n'en a pas. Pas de repository dédié
 * (accès via Film::getParts()). Contrainte d'unicité (film_id, number).
 */
#[ORM\Entity]
#[ORM\Table(name: 'film_part')]
#[ORM\UniqueConstraint(name: 'uniq_film_part_number', columns: ['film_id', 'number'])]
class FilmPart
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /**
     * Film parent — côté propriétaire. FK en ON DELETE CASCADE : les parties
     * disparaissent aussi quand le film est supprimé directement en SQL.
     */
    #[ORM\ManyToOne(targetEntity: Film::class, inversedBy: 'parts')]
    #[ORM\JoinColumn(name: 'film_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Film $film;

    /** Rang de la partie dans le film, 1-based. */
    #[ORM\Column]
    private int $number;

    /** Libellé de la partie (à l'import : nom du dossier Bunny de la vidéo). */
    #[ORM\Column(length: 255)]
    private string $title;

    /** Chemin Bunny du dossier vidéo de cette partie (sans `/master.m3u8`). */
    #[ORM\Column(length: 500)]
    private string $bunnyVideoId;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid { return $this->id; }
    public function getFilm(): Film { return $this->film; }
    public function setFilm(Film $film): static { $this->film = $film; return $this; }
    public function getNumber(): int { return $this->number; }
    public function setNumber(int $number): static { $this->number = $number; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }
    public function getBunnyVideoId(): string { return $this->bunnyVideoId; }
    public function setBunnyVideoId(string $id): static { $this->bunnyVideoId = $id; return $this; }

    /**
     * Sérialise la partie : id, number, title, bunnyVideoId.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'number' => $this->number,
            'title' => $this->title,
            'bunnyVideoId' => $this->bunnyVideoId,
        ];
    }
}
