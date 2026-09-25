<?php
namespace App\Repository;

use App\Entity\Tag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Accès aux étiquettes éditoriales (Tag). Aucune requête spécifique, et aucun
 * appelant dans le code actuel (les tags ne sont exploités nulle part).
 */
class TagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }
}
