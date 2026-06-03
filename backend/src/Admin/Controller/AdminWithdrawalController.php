<?php

namespace App\Admin\Controller;

use App\Entity\Film;
use App\Entity\Serie;
use App\Entity\User;
use App\Entity\WithdrawalRequest;
use App\Studio\Service\ContentLifecycleService;
use App\Repository\FilmRepository;
use App\Repository\SerieRepository;
use App\Repository\WithdrawalRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Endpoints admin pour gérer les demandes de retrait (WithdrawalRequest)
 * créées par les producteurs : approve / reject.
 */
#[Route('/api/admin/withdrawals')]
#[IsGranted('ROLE_ADMIN')]
class AdminWithdrawalController extends AbstractController
{
    private const ALLOWED_STATUSES = [
        WithdrawalRequest::STATUS_PENDING,
        WithdrawalRequest::STATUS_APPROVED,
        WithdrawalRequest::STATUS_REJECTED,
    ];

    public function __construct(
        private readonly WithdrawalRequestRepository $withdrawalRepo,
        private readonly FilmRepository $filmRepo,
        private readonly SerieRepository $serieRepo,
        private readonly ContentLifecycleService $lifecycle,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'admin_withdrawals_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));
        $status = $request->query->get('status', WithdrawalRequest::STATUS_PENDING);

        if ($status !== null && $status !== '' && !\in_array($status, self::ALLOWED_STATUSES, true)) {
            return $this->json(['message' => 'Statut invalide.'], 400);
        }

        $items = $this->withdrawalRepo->findAllPaginated($status, $page, $limit);
        $total = $this->withdrawalRepo->countAll($status);

        return $this->json([
            'data' => array_map(fn (WithdrawalRequest $w) => $this->serializeWithdrawal($w), $items),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    #[Route('/{id}', name: 'admin_withdrawals_get', methods: ['GET'])]
    public function getOne(string $id): JsonResponse
    {
        $withdrawal = $this->resolveWithdrawal($id);
        if ($withdrawal instanceof JsonResponse) {
            return $withdrawal;
        }

        return $this->json($this->serializeWithdrawal($withdrawal));
    }

    #[Route('/{id}/approve', name: 'admin_withdrawals_approve', methods: ['POST'])]
    public function approve(string $id, Request $request): JsonResponse
    {
        $withdrawal = $this->resolveWithdrawal($id);
        if ($withdrawal instanceof JsonResponse) {
            return $withdrawal;
        }

        if ($withdrawal->getStatus() !== WithdrawalRequest::STATUS_PENDING) {
            return $this->json(['message' => 'La demande a déjà été traitée.'], 409);
        }

        $reviewNote = $this->extractReviewNote($request);

        // Charger l'entité cible
        $targetType = $withdrawal->getTargetType();
        $targetId = $withdrawal->getTargetId();
        $targetArray = null;

        if ($targetType === WithdrawalRequest::TARGET_FILM) {
            $film = $this->filmRepo->find($targetId);
            if ($film === null) {
                return $this->json(['message' => 'Cible introuvable.'], 404);
            }
            if ($film->getStatus() === Film::STATUS_WITHDRAWN) {
                return $this->json(['message' => 'Le film est déjà retiré.'], 409);
            }
            $this->lifecycle->markFilmWithdrawn($film);
            $targetArray = $film->toArray();
        } elseif ($targetType === WithdrawalRequest::TARGET_SERIE) {
            $serie = $this->serieRepo->find($targetId);
            if ($serie === null) {
                return $this->json(['message' => 'Cible introuvable.'], 404);
            }
            if ($serie->getStatus() === Serie::STATUS_WITHDRAWN) {
                return $this->json(['message' => 'La série est déjà retirée.'], 409);
            }
            $this->lifecycle->markSerieWithdrawn($serie);
            $targetArray = $serie->toArray();
        } else {
            return $this->json(['message' => 'Type de cible inconnu.'], 400);
        }

        /** @var User $admin */
        $admin = $this->getUser();
        $withdrawal->approve($admin, $reviewNote);
        $this->em->flush();

        return $this->json([
            'withdrawal' => $this->serializeWithdrawal($withdrawal),
            'target' => $targetArray,
        ]);
    }

    #[Route('/{id}/reject', name: 'admin_withdrawals_reject', methods: ['POST'])]
    public function reject(string $id, Request $request): JsonResponse
    {
        $withdrawal = $this->resolveWithdrawal($id);
        if ($withdrawal instanceof JsonResponse) {
            return $withdrawal;
        }

        if ($withdrawal->getStatus() !== WithdrawalRequest::STATUS_PENDING) {
            return $this->json(['message' => 'La demande a déjà été traitée.'], 409);
        }

        $reviewNote = $this->extractReviewNote($request);

        /** @var User $admin */
        $admin = $this->getUser();
        $withdrawal->reject($admin, $reviewNote);
        $this->em->flush();

        return $this->json($this->serializeWithdrawal($withdrawal));
    }

    private function extractReviewNote(Request $request): ?string
    {
        $content = $request->getContent();
        if ($content === '') {
            return null;
        }
        $data = json_decode($content, true);
        if (!\is_array($data) || !\array_key_exists('reviewNote', $data) || $data['reviewNote'] === null) {
            return null;
        }
        $note = trim((string) $data['reviewNote']);

        return $note === '' ? null : $note;
    }

    private function resolveWithdrawal(string $id): WithdrawalRequest|JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            return $this->json(['message' => 'Identifiant invalide.'], 400);
        }

        $withdrawal = $this->withdrawalRepo->find($uuid);
        if ($withdrawal === null) {
            return $this->json(['message' => 'Demande de retrait introuvable.'], 404);
        }

        return $withdrawal;
    }

    /**
     * Sérialise une demande avec studio + requestedBy + reviewedBy enrichis.
     */
    private function serializeWithdrawal(WithdrawalRequest $w): array
    {
        $row = $w->toArray();
        $studio = $w->getStudio();
        $row['studio'] = [
            'id' => $studio->getId()->toRfc4122(),
            'name' => $studio->getName(),
            'slug' => $studio->getSlug(),
        ];
        $requestedBy = $w->getRequestedBy();
        $row['requestedBy'] = [
            'id' => $requestedBy->getId()->toRfc4122(),
            'email' => $requestedBy->getEmail(),
            'firstName' => $requestedBy->getFirstName(),
            'lastName' => $requestedBy->getLastName(),
        ];
        $reviewedBy = $w->getReviewedBy();
        $row['reviewedBy'] = $reviewedBy === null ? null : [
            'id' => $reviewedBy->getId()->toRfc4122(),
            'email' => $reviewedBy->getEmail(),
            'firstName' => $reviewedBy->getFirstName(),
            'lastName' => $reviewedBy->getLastName(),
        ];

        return $row;
    }
}
