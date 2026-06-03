<?php

namespace App\Admin\Controller;

use App\Entity\Studio;
use App\Repository\StudioRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Endpoint admin minimaliste pour récupérer la liste des studios actifs,
 * utilisé par l'UI admin (filtres par studio dans /admin/films-series).
 */
#[Route('/api/admin/studios')]
#[IsGranted('ROLE_ADMIN')]
class AdminStudioController extends AbstractController
{
    public function __construct(
        private readonly StudioRepository $studioRepo,
    ) {
    }

    #[Route('', name: 'admin_studios_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $studios = $this->studioRepo->findActiveOrdered();

        return new JsonResponse([
            'data' => array_map(fn (Studio $s) => $s->toArray(), $studios),
            'total' => \count($studios),
        ]);
    }
}
