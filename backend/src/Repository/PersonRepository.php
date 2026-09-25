<?php
namespace App\Repository;

use App\Entity\Person;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Accès au référentiel des personnes (réalisateurs, acteurs). Aucune requête
 * spécifique : GET /api/persons (CatalogueReferenceController) et le PATCH
 * admin /api/films/{id} (réalisateurs, distribution) utilisent findBy() / find() hérités.
 */
class PersonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Person::class);
    }
}
