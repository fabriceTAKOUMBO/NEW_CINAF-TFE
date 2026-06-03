<?php
namespace App\Repository;

use App\Entity\Genre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class GenreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Genre::class);
    }

    public function findBySlug(string $slug): ?Genre
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    public function findByName(string $name): ?Genre
    {
        return $this->findOneBy(['name' => $name]);
    }
}
