<?php
namespace App\Repository;

use App\Entity\Film;
use App\Entity\Studio;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Requêtes sur les films, pour les trois contextes de l'application :
 *  - public (/api/films, catalogue discover, chaîne publique d'un studio) :
 *    toujours appelées avec le statut PUBLISHED pour ne rien exposer d'autre ;
 *  - admin (/api/admin/films) : findAllPaginated() / countAll(), tous statuts ;
 *  - studio (/api/studio/films, tableau de bord) : findByStudioPaginated(),
 *    countByStudio*().
 *
 * Convention : un paramètre `$status` null ou '' signifie « pas de filtre de
 * statut ». Les pages sont numérotées à partir de 1.
 */
class FilmRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Film::class);
    }

    /**
     * Liste paginée admin : tous les films, filtres optionnels (status,
     * studioId, recherche par titre).
     *
     * Aussi utilisée par le catalogue public (CatalogueDiscoverController,
     * source `db`) avec status = PUBLISHED. Tri : plus récents d'abord ;
     * recherche insensible à la casse, sur le titre seul.
     *
     * @return Film[]
     */
    public function findAllPaginated(
        ?string $status,
        ?Uuid $studioId,
        ?string $search,
        int $page,
        int $limit,
    ): array {
        $qb = $this->buildAllPaginatedQuery($status, $studioId, $search)
            ->orderBy('f.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    /** Nombre total de films correspondant aux mêmes filtres que findAllPaginated(). */
    public function countAll(?string $status, ?Uuid $studioId, ?string $search): int
    {
        $qb = $this->buildAllPaginatedQuery($status, $studioId, $search)
            ->select('COUNT(f.id)');

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Requête commune à findAllPaginated() et countAll() : garantit que la
     * liste et son total appliquent exactement les mêmes filtres.
     */
    private function buildAllPaginatedQuery(?string $status, ?Uuid $studioId, ?string $search): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('f');

        if ($status !== null && $status !== '') {
            $qb->andWhere('f.status = :status')->setParameter('status', $status);
        }
        if ($studioId !== null) {
            // Comparaison directe sur la FK studio_id ; le type 'uuid' fait
            // convertir l'objet Uuid par le type Doctrine de Symfony.
            $qb->andWhere('f.studio = :studioId')->setParameter('studioId', $studioId, 'uuid');
        }
        if ($search !== null && $search !== '') {
            $qb->andWhere('LOWER(f.title) LIKE :q')
               ->setParameter('q', '%' . strtolower($search) . '%');
        }

        return $qb;
    }

    /**
     * Liste paginée des films d'un studio, optionnellement filtrée par status.
     *
     * Sert au module Studio (tous statuts) et à la chaîne publique du studio
     * (StudioPublicController, avec PUBLISHED). Tri : plus récents d'abord.
     *
     * @return array{data: Film[], total: int, page: int, limit: int}
     */
    public function findByStudioPaginated(Studio $studio, ?string $status, int $page, int $limit): array
    {
        $qb = $this->createQueryBuilder('f')
            ->andWhere('f.studio = :studio')
            ->setParameter('studio', $studio)
            ->orderBy('f.createdAt', 'DESC');

        if ($status !== null && $status !== '') {
            $qb->andWhere('f.status = :status')->setParameter('status', $status);
        }

        // Total calculé sur une copie sans ORDER BY (inutile pour un COUNT),
        // avant que la pagination ne soit appliquée à la requête principale.
        $countQb = clone $qb;
        $countQb->resetDQLPart('orderBy');
        $total = (int) $countQb->select('COUNT(f.id)')->getQuery()->getSingleScalarResult();

        $items = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['data' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    /** Nombre de films d'un studio dans un statut donné (tableau de bord studio, fiche publique). */
    public function countByStudioAndStatus(Studio $studio, string $status): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->andWhere('f.studio = :studio')
            ->andWhere('f.status = :status')
            ->setParameter('studio', $studio)
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Nombre de films par studio pour un statut, en UNE requête (les listes
     * publiques affichaient un COUNT par studio : N+1).
     *
     * @param  list<Studio>       $studios
     * @return array<string, int> id du studio (RFC 4122) => nombre
     */
    public function countByStudiosAndStatus(array $studios, string $status): array
    {
        if ($studios === []) {
            return [];
        }
        // IDENTITY() lit directement la FK studio_id, sans jointure sur studio.
        // Un studio sans film dans ce statut est absent du résultat : l'appelant
        // doit prévoir la valeur par défaut 0.
        $rows = $this->createQueryBuilder('f')
            ->select('IDENTITY(f.studio) AS studioId, COUNT(f.id) AS nb')
            ->andWhere('f.studio IN (:studios)')
            ->andWhere('f.status = :status')
            ->setParameter('studios', $studios)
            ->setParameter('status', $status)
            ->groupBy('f.studio')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['studioId']] = (int) $row['nb'];
        }
        return $counts;
    }

    /** Nombre total de films d'un studio, tous statuts confondus (tableau de bord studio). */
    public function countByStudio(Studio $studio): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->andWhere('f.studio = :studio')
            ->setParameter('studio', $studio)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Liste paginée du catalogue public GET /api/films (plus récents d'abord).
     *
     * @return array{data: Film[], total: int, page: int, limit: int}
     */
    public function findPaginated(int $page = 1, int $limit = 30, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('f')
            ->orderBy('f.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        if ($status !== null && $status !== '') {
            $qb->andWhere('f.status = :status')->setParameter('status', $status);
        }

        $items = $qb->getQuery()->getResult();
        // Le total applique le même filtre de statut que la liste (count() natif).
        $total = $status === null || $status === ''
            ? $this->count([])
            : $this->count(['status' => $status]);

        return ['data' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    /**
     * Recherche du catalogue public GET /api/films/search.
     *
     * Texte libre sur titre + synopsis (insensible à la casse), combiné aux
     * filtres optionnels. Tri : plus récents d'abord.
     *
     * Limite connue : les LEFT JOIN sur genres et pays peuvent dupliquer les
     * lignes SQL d'un même film. Le total compte les films distincts, mais
     * LIMIT/OFFSET portent sur les lignes SQL : une page peut alors contenir
     * moins de `$limit` films (Doctrine fusionne les doublons à l'hydratation).
     *
     * @param array<string, mixed> $filters Clés reconnues : `genre` (nom ou slug),
     *                                      `year`, `country` (nom ou code ISO) ;
     *                                      toute autre clé (ex. `lang`) est ignorée.
     *
     * @return array{data: Film[], total: int, page: int, limit: int}
     */
    public function search(?string $q, array $filters = [], int $page = 1, int $limit = 30, ?string $status = null): array
    {
        // Jointures nécessaires aux filtres genre / pays (non chargées : seul `f` est sélectionné).
        $qb = $this->createQueryBuilder('f')
            ->leftJoin('f.genres', 'g')
            ->leftJoin('f.countries', 'c');

        if ($q) {
            // andWhere() place entre parenthèses une expression contenant OR :
            // le OR ne « s'échappe » pas des autres conditions.
            $qb->andWhere('LOWER(f.title) LIKE :q OR LOWER(f.synopsis) LIKE :q')
               ->setParameter('q', '%' . strtolower($q) . '%');
        }
        if (!empty($filters['genre'])) {
            $qb->andWhere('g.name = :genre OR g.slug = :genre')
               ->setParameter('genre', $filters['genre']);
        }
        if (!empty($filters['year'])) {
            $qb->andWhere('f.year = :year')->setParameter('year', (int) $filters['year']);
        }
        if (!empty($filters['country'])) {
            $qb->andWhere('c.name = :country OR c.isoCode = :country')
               ->setParameter('country', $filters['country']);
        }
        if ($status !== null && $status !== '') {
            $qb->andWhere('f.status = :status')->setParameter('status', $status);
        }

        // DISTINCT : un film joint à plusieurs genres/pays ne doit compter qu'une fois.
        $total = (int) (clone $qb)->select('COUNT(DISTINCT f.id)')->getQuery()->getSingleScalarResult();

        $items = $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return ['data' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    /**
     * Films les plus vus (compteur `views` décroissant), pour /api/films/trending.
     *
     * @return Film[]
     */
    public function findTrending(int $limit = 10, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('f')
            ->orderBy('f.views', 'DESC')
            ->setMaxResults($limit);

        // Filtrage du statut côté SQL : évite de récupérer N films puis d'en
        // filtrer une partie en mémoire (ce qui tronquait silencieusement la liste).
        if ($status !== null && $status !== '') {
            $qb->andWhere('f.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Derniers films ajoutés (date de création en base, pas de publication),
     * pour /api/films/new.
     *
     * @return Film[]
     */
    public function findNew(int $limit = 10, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('f')
            ->orderBy('f.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($status !== null && $status !== '') {
            $qb->andWhere('f.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }
}
