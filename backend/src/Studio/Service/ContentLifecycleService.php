<?php

namespace App\Studio\Service;

use App\Entity\Film;
use App\Entity\Serie;
use App\Entity\Studio;
use App\Entity\User;
use App\Entity\WithdrawalRequest;
use App\Repository\WithdrawalRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Gestion centralisée du cycle de vie d'un contenu (Film/Serie) :
 * DRAFT → PUBLISHED → (demande de retrait) → WITHDRAWN.
 *
 * Toutes les transitions sont stateful et synchronisées avec la base de
 * données via flush. La création d'une demande de retrait est protégée
 * contre les doublons (409 si une demande PENDING existe déjà).
 */
class ContentLifecycleService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WithdrawalRequestRepository $withdrawalRepo,
    ) {
    }

    /**
     * Publie un film côté studio. Si le studio n'est pas encore validé
     * (premier contenu en self-service), le film bascule en
     * `PENDING_APPROVAL` au lieu de `PUBLISHED`. Une fois l'admin
     * approbateur passé, `studio.isValidated` devient `true` et les
     * publish suivants partent directement en `PUBLISHED`.
     *
     * @throws BadRequestHttpException si le film n'est pas en DRAFT.
     */
    public function publishFilm(Film $film): void
    {
        if ($film->getStatus() !== Film::STATUS_DRAFT) {
            throw new BadRequestHttpException(
                sprintf('Seul un film en DRAFT peut être publié (statut actuel: %s).', $film->getStatus())
            );
        }
        $studio = $film->getStudio();
        if ($studio !== null && !$studio->isValidated()) {
            $film->setStatus(Film::STATUS_PENDING_APPROVAL);
        } else {
            $film->setStatus(Film::STATUS_PUBLISHED);
            $film->setPublishedAt(new \DateTimeImmutable());
        }
        $this->em->flush();
    }

    /**
     * Publie une série côté studio. Comme pour les films, si le studio
     * n'est pas encore validé, la série passe en `PENDING_APPROVAL` et
     * attend l'approbation admin.
     *
     * @throws BadRequestHttpException si la série n'est pas en DRAFT.
     */
    public function publishSerie(Serie $serie): void
    {
        if ($serie->getStatus() !== Serie::STATUS_DRAFT) {
            throw new BadRequestHttpException(
                sprintf('Seule une série en DRAFT peut être publiée (statut actuel: %s).', $serie->getStatus())
            );
        }
        $studio = $serie->getStudio();
        if ($studio !== null && !$studio->isValidated()) {
            $serie->setStatus(Serie::STATUS_PENDING_APPROVAL);
        } else {
            $serie->setStatus(Serie::STATUS_PUBLISHED);
            $serie->setPublishedAt(new \DateTimeImmutable());
        }
        $this->em->flush();
    }

    /**
     * Approbation admin d'un film en `PENDING_APPROVAL` : passage à
     * `PUBLISHED` et validation du studio si nécessaire. Idempotent en
     * partie : on refuse explicitement si le film n'est pas en
     * `PENDING_APPROVAL`.
     *
     * @throws BadRequestHttpException si le film n'est pas en PENDING_APPROVAL.
     */
    public function approveFilm(Film $film): void
    {
        if ($film->getStatus() !== Film::STATUS_PENDING_APPROVAL) {
            throw new BadRequestHttpException(
                sprintf('Seul un film en PENDING_APPROVAL peut être approuvé (statut actuel: %s).', $film->getStatus())
            );
        }
        $film->setStatus(Film::STATUS_PUBLISHED);
        $film->setPublishedAt(new \DateTimeImmutable());
        $studio = $film->getStudio();
        if ($studio !== null && !$studio->isValidated()) {
            $studio->setIsValidated(true);
        }
        $this->em->flush();
    }

    /**
     * Approbation admin d'une série en `PENDING_APPROVAL`.
     *
     * @throws BadRequestHttpException si la série n'est pas en PENDING_APPROVAL.
     */
    public function approveSerie(Serie $serie): void
    {
        if ($serie->getStatus() !== Serie::STATUS_PENDING_APPROVAL) {
            throw new BadRequestHttpException(
                sprintf('Seule une série en PENDING_APPROVAL peut être approuvée (statut actuel: %s).', $serie->getStatus())
            );
        }
        $serie->setStatus(Serie::STATUS_PUBLISHED);
        $serie->setPublishedAt(new \DateTimeImmutable());
        $studio = $serie->getStudio();
        if ($studio !== null && !$studio->isValidated()) {
            $studio->setIsValidated(true);
        }
        $this->em->flush();
    }

    /**
     * Refus admin d'un film en `PENDING_APPROVAL` : retour en `DRAFT`,
     * le studio reste non validé. Le studio peut alors modifier puis
     * resoumettre.
     *
     * @throws BadRequestHttpException si le film n'est pas en PENDING_APPROVAL.
     */
    public function rejectFilm(Film $film): void
    {
        if ($film->getStatus() !== Film::STATUS_PENDING_APPROVAL) {
            throw new BadRequestHttpException(
                sprintf('Seul un film en PENDING_APPROVAL peut être refusé (statut actuel: %s).', $film->getStatus())
            );
        }
        $film->setStatus(Film::STATUS_DRAFT);
        $this->em->flush();
    }

    /**
     * Refus admin d'une série en `PENDING_APPROVAL` : retour en `DRAFT`.
     *
     * @throws BadRequestHttpException si la série n'est pas en PENDING_APPROVAL.
     */
    public function rejectSerie(Serie $serie): void
    {
        if ($serie->getStatus() !== Serie::STATUS_PENDING_APPROVAL) {
            throw new BadRequestHttpException(
                sprintf('Seule une série en PENDING_APPROVAL peut être refusée (statut actuel: %s).', $serie->getStatus())
            );
        }
        $serie->setStatus(Serie::STATUS_DRAFT);
        $this->em->flush();
    }

    /**
     * Crée une demande de retrait. Refuse le doublon : 409 si une demande
     * PENDING existe déjà pour ce contenu.
     *
     * @throws ConflictHttpException
     */
    public function requestWithdrawal(
        string $targetType,
        Uuid $targetId,
        Studio $studio,
        User $requestedBy,
        string $reason,
    ): WithdrawalRequest {
        $existing = $this->withdrawalRepo->findActivePendingFor($targetType, $targetId);
        if ($existing !== null) {
            throw new ConflictHttpException('Une demande de retrait est déjà en attente pour ce contenu.');
        }

        $request = new WithdrawalRequest();
        $request->setStudio($studio);
        $request->setRequestedBy($requestedBy);
        $request->setTargetType($targetType);
        $request->setTargetId($targetId);
        $request->setReason($reason);
        // STATUS_PENDING par défaut

        $this->em->persist($request);
        $this->em->flush();

        return $request;
    }

    /**
     * Marque un film comme retiré (utilisé par l'admin lors de l'approbation
     * d'une WithdrawalRequest — Agent 3).
     */
    public function markFilmWithdrawn(Film $film): void
    {
        $film->setStatus(Film::STATUS_WITHDRAWN);
        $film->setWithdrawnAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    public function markSerieWithdrawn(Serie $serie): void
    {
        $serie->setStatus(Serie::STATUS_WITHDRAWN);
        $serie->setWithdrawnAt(new \DateTimeImmutable());
        $this->em->flush();
    }
}
