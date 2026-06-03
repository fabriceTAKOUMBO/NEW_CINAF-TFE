<?php

namespace App\Studio\Service;

use App\Entity\Film;
use App\Entity\Serie;
use App\Entity\Studio;
use App\Entity\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Vérifie qu'un user (créateur) est bien propriétaire d'un studio
 * et, par extension, des contenus rattachés à ce studio.
 *
 * Lance systématiquement AccessDeniedHttpException (403) en cas d'échec —
 * ne distingue PAS volontairement "studio inactif" et "non-propriétaire"
 * pour ne pas divulguer l'existence d'un studio à un tiers.
 */
class StudioOwnershipChecker
{
    /**
     * @throws AccessDeniedHttpException
     */
    public function assertOwns(Studio $studio, User $user): void
    {
        if ($studio->getOwner()->getId()->toRfc4122() !== $user->getId()->toRfc4122()
            || !$studio->isActive()
        ) {
            throw new AccessDeniedHttpException("Vous n'êtes pas propriétaire de ce studio.");
        }
    }

    /**
     * Récupère le studio actif du user courant ou jette 403.
     *
     * @throws AccessDeniedHttpException
     */
    public function getStudioForUser(User $user): Studio
    {
        $studio = $user->getStudio();
        if ($studio === null || !$studio->isActive()) {
            throw new AccessDeniedHttpException('Aucun studio actif associé à cet utilisateur.');
        }

        return $studio;
    }

    /**
     * @throws AccessDeniedHttpException
     */
    public function assertOwnsFilm(Film $film, User $user): void
    {
        $studio = $film->getStudio();
        if ($studio === null) {
            throw new AccessDeniedHttpException("Vous n'êtes pas propriétaire de ce studio.");
        }
        $this->assertOwns($studio, $user);
    }

    /**
     * @throws AccessDeniedHttpException
     */
    public function assertOwnsSerie(Serie $serie, User $user): void
    {
        $studio = $serie->getStudio();
        if ($studio === null) {
            throw new AccessDeniedHttpException("Vous n'êtes pas propriétaire de ce studio.");
        }
        $this->assertOwns($studio, $user);
    }

    /**
     * Phase H (hardening) — vérifie qu'un chemin Bunny appartient bien au
     * studio donné. Un producteur ne doit pas pouvoir pointer un
     * `bunnyVideoId` vers le path d'un autre studio.
     *
     * Règle : le chemin doit commencer par `studios/{studio.slug}/`.
     *
     * @throws AccessDeniedHttpException si le chemin ne matche pas.
     */
    public function assertBunnyPathOwnership(string $path, Studio $studio): void
    {
        $expectedPrefix = sprintf('studios/%s/', $studio->getSlug());
        if (!str_starts_with($path, $expectedPrefix)) {
            throw new AccessDeniedHttpException('Le chemin Bunny ne correspond pas à votre studio.');
        }
    }

    /**
     * Détermine si un `bunnyVideoId` est issu d'un import Bunny → DB
     * (commande `app:catalogue:import-bunny`, Phase F). Les contenus
     * importés ont un `bunnyVideoId` qui ne commence PAS par `studios/`
     * (ex: `12_CAS/CAS_1/CAS1_E01`). Pour ces contenus, le PATCH du champ
     * `bunnyVideoId` est interdit côté producteur (l'admin peut le faire
     * via /api/admin/films/{id} sans cette contrainte).
     */
    public function isImportedBunnyPath(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }
        return !str_starts_with($path, 'studios/');
    }
}
