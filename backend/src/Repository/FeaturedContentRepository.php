<?php
namespace App\Repository;

use App\Entity\FeaturedContent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class FeaturedContentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FeaturedContent::class);
    }

    public function findActive(): array
    {
        $now = new \DateTimeImmutable();
        return $this->createQueryBuilder('fc')
            ->where('fc.active = :active')
            ->andWhere('fc.startDate <= :now')
            ->andWhere('fc.endDate IS NULL OR fc.endDate >= :now')
            ->setParameter('active', true)
            ->setParameter('now', $now)
            ->orderBy('fc.position', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
