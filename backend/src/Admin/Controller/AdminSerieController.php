<?php

namespace App\Admin\Controller;

use App\Entity\Serie;
use App\Repository\SerieRepository;
use App\Repository\StudioRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Endpoints admin pour la gestion globale des séries, tous studios confondus.
 */
#[Route('/api/admin/series')]
#[IsGranted('ROLE_ADMIN')]
class AdminSerieController extends AbstractController
{
    private const ALLOWED_STATUSES = [
        Serie::STATUS_DRAFT,
        Serie::STATUS_PUBLISHED,
        Serie::STATUS_WITHDRAWN,
    ];

    public function __construct(
        private readonly SerieRepository $serieRepo,
        private readonly StudioRepository $studioRepo,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'admin_series_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));
        $status = $request->query->get('status');
        $studioIdRaw = $request->query->get('studioId');
        $search = $request->query->get('search');

        if ($status !== null && $status !== '' && !\in_array($status, self::ALLOWED_STATUSES, true)) {
            return $this->json(['message' => 'Statut invalide.'], 400);
        }

        $studioUuid = null;
        if ($studioIdRaw !== null && $studioIdRaw !== '') {
            try {
                $studioUuid = Uuid::fromString($studioIdRaw);
            } catch (\InvalidArgumentException) {
                return $this->json(['message' => 'studioId invalide.'], 400);
            }
        }

        $items = $this->serieRepo->findAllPaginated($status, $studioUuid, $search, $page, $limit);
        $total = $this->serieRepo->countAll($status, $studioUuid, $search);

        return $this->json([
            'data' => array_map(fn (Serie $s) => $this->serializeWithStudio($s), $items),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    #[Route('/{id}', name: 'admin_series_get', methods: ['GET'])]
    public function getOne(string $id): JsonResponse
    {
        $serie = $this->resolveSerie($id);
        if ($serie instanceof JsonResponse) {
            return $serie;
        }

        return $this->json($this->serializeWithStudio($serie, true));
    }

    #[Route('/{id}', name: 'admin_series_update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $serie = $this->resolveSerie($id);
        if ($serie instanceof JsonResponse) {
            return $serie;
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['message' => 'Corps JSON invalide.'], 400);
        }

        if (\array_key_exists('title', $data)) {
            $title = trim((string) $data['title']);
            if ($title === '') {
                return $this->json(['message' => 'Le titre ne peut pas être vide.'], 400);
            }
            $serie->setTitle($title);
        }

        if (\array_key_exists('synopsis', $data)) {
            $serie->setSynopsis((string) $data['synopsis']);
        }

        if (\array_key_exists('year', $data)) {
            $serie->setYear((int) $data['year']);
        }

        if (\array_key_exists('poster', $data)) {
            $serie->setPoster($data['poster'] === null ? null : (string) $data['poster']);
        }

        if (\array_key_exists('trailerVideoId', $data)) {
            $serie->setTrailerVideoId($data['trailerVideoId'] === null ? null : (string) $data['trailerVideoId']);
        }

        if (\array_key_exists('status', $data)) {
            $newStatus = (string) $data['status'];
            if (!\in_array($newStatus, self::ALLOWED_STATUSES, true)) {
                return $this->json(['message' => 'Statut invalide.'], 400);
            }
            $previous = $serie->getStatus();
            $serie->setStatus($newStatus);
            if ($newStatus === Serie::STATUS_PUBLISHED && $previous !== Serie::STATUS_PUBLISHED && $serie->getPublishedAt() === null) {
                $serie->setPublishedAt(new \DateTimeImmutable());
            }
            if ($newStatus === Serie::STATUS_WITHDRAWN && $previous !== Serie::STATUS_WITHDRAWN) {
                $serie->setWithdrawnAt(new \DateTimeImmutable());
            }
        }

        if (\array_key_exists('studioId', $data)) {
            if ($data['studioId'] === null) {
                $serie->setStudio(null);
            } else {
                try {
                    $studioUuid = Uuid::fromString((string) $data['studioId']);
                } catch (\InvalidArgumentException) {
                    return $this->json(['message' => 'studioId invalide.'], 400);
                }
                $studio = $this->studioRepo->find($studioUuid);
                if ($studio === null) {
                    return $this->json(['message' => 'Studio introuvable.'], 404);
                }
                $serie->setStudio($studio);
            }
        }

        $this->em->flush();

        return $this->json($this->serializeWithStudio($serie, true));
    }

    #[Route('/{id}', name: 'admin_series_delete', methods: ['DELETE'])]
    public function delete(string $id): Response
    {
        $serie = $this->resolveSerie($id);
        if ($serie instanceof JsonResponse) {
            return $serie;
        }

        $this->em->remove($serie);
        $this->em->flush();

        return new Response('', 204);
    }

    private function resolveSerie(string $id): Serie|JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            return $this->json(['message' => 'Identifiant invalide.'], 400);
        }

        $serie = $this->serieRepo->find($uuid);
        if ($serie === null) {
            return $this->json(['message' => 'Série introuvable.'], 404);
        }

        return $serie;
    }

    private function serializeWithStudio(Serie $serie, bool $expand = false): array
    {
        $row = $serie->toArray($expand);
        $studio = $serie->getStudio();
        $row['studio'] = $studio === null ? null : [
            'id' => $studio->getId()->toRfc4122(),
            'name' => $studio->getName(),
            'slug' => $studio->getSlug(),
        ];

        return $row;
    }
}
