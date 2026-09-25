<?php
namespace App\Repository;

use App\Entity\Language;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Accès au référentiel des langues (Language). La liste publique
 * GET /api/languages utilise findBy() hérité (CatalogueReferenceController).
 */
class LanguageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Language::class);
    }

    /** Langue dont le code vaut exactement `$code`, ou null (aucun appelant dans le code actuel). */
    public function findByIsoCode(string $code): ?Language
    {
        return $this->findOneBy(['isoCode' => $code]);
    }
}
