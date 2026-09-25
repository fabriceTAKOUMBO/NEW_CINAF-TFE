<?php

namespace App\Tests\Admin\Controller;

use App\Entity\Film;
use App\Entity\Serie;
use App\Entity\Studio;
use App\Entity\User;
use App\Tests\Studio\Support\StudioTestTrait;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Tests fonctionnels du workflow d'approbation admin :
 *   - GET    /api/admin/approvals
 *   - PATCH  /api/admin/films/{id}/approve | reject
 *   - PATCH  /api/admin/series/{id}/approve | reject
 *
 * NB : tous les tests bootent le kernel une seule fois via `createClient()`
 * en début de test, puis instancient les entités via l'EM directement
 * (le kernel Symfony refuse un double-boot).
 *
 * Contrôleur testé : App\Admin\Controller\AdminApprovalController. Règles
 * vérifiées : le premier contenu d'un studio non validé
 * (`Studio.isValidated = false`) attend en PENDING_APPROVAL ; l'approbation le
 * publie (PUBLISHED) ET valide le studio ; le refus le renvoie en DRAFT sans
 * valider le studio ; approuver un contenu qui n'est pas en attente donne 409 ;
 * un non-admin, même créateur du contenu, reçoit 403. Le refus d'une série
 * (`/series/{id}/reject`) n'a pas de test dédié dans ce fichier.
 *
 * Données : chaque test crée son créateur + studio (StudioTestTrait, qui le
 * crée déjà validé : on le repasse à `isValidated = false` quand le scénario
 * l'exige), ses contenus directement au statut voulu (sans passer par l'API de
 * publication) et un administrateur dédié (createAdminToken()).
 *
 * Lancement : `php bin/phpunit tests/Admin/Controller/AdminApprovalControllerTest.php`.
 */
class AdminApprovalControllerTest extends ApiTestCase
{
    use StudioTestTrait;

    /**
     * File d'approbation : un film et une série en PENDING_APPROVAL d'un studio
     * non validé apparaissent dans `GET /api/admin/approvals` (200, format paginé
     * `{data, total, page, limit}`), avec leur `kind` (`film` / `serie`) et un
     * résumé de studio indiquant `isValidated: false`.
     */
    public function testListReturnsPendingApprovals(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // Studio non validé + 1 film + 1 série en PENDING_APPROVAL.
        [, $studio, ] = $this->createCreatorWithStudio();
        $studio->setIsValidated(false);
        $em->flush();

        $this->createFilmInStudio($em, $studio, 'Film en attente', Film::STATUS_PENDING_APPROVAL);
        $this->createSerieInStudio($em, $studio, 'Série en attente', Serie::STATUS_PENDING_APPROVAL);

        $adminToken = $this->createAdminToken();

        // Sans `page` ni `limit` : première page de 20 éléments, triée par date
        // de mise à jour décroissante ; les deux contenus créés ci-dessus, les
        // plus récents, y figurent donc.
        $response = $this->getJson($client, '/api/admin/approvals', $adminToken);
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $this->assertPaginatedStructure($body);
        $this->assertGreaterThanOrEqual(2, $body['total']);

        $kinds = array_column($body['data'], 'kind');
        $this->assertContains('film', $kinds);
        $this->assertContains('serie', $kinds);

        // Au moins une entrée doit pointer sur le studio non validé
        // créé ci-dessus (la base de test peut contenir d'autres pending
        // créés par d'autres méthodes — on filtre).
        $myStudioRows = array_filter(
            $body['data'],
            fn (array $r) => isset($r['studio']['id']) && $r['studio']['id'] === $studio->getId()->toRfc4122(),
        );
        $this->assertGreaterThanOrEqual(2, \count($myStudioRows));
        foreach ($myStudioRows as $row) {
            $this->assertFalse($row['studio']['isValidated']);
        }
    }

    /**
     * Approbation d'un film en attente : 200, le film renvoyé est PUBLISHED et,
     * en base, son studio est désormais validé (`isValidated = true`).
     */
    public function testApproveFilmPublishesAndValidatesStudio(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [, $studio, ] = $this->createCreatorWithStudio();
        // Le trait crée un studio déjà validé : on le repasse en « non validé »
        // pour reproduire le cas d'un nouveau studio dont le 1er contenu attend.
        $studio->setIsValidated(false);
        $em->flush();

        $film = $this->createFilmInStudio($em, $studio, 'À approuver', Film::STATUS_PENDING_APPROVAL);

        $adminToken = $this->createAdminToken();

        $response = $this->patchJson(
            $client,
            '/api/admin/films/' . $film->getId()->toRfc4122() . '/approve',
            [],
            $adminToken,
        );
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);
        $this->assertSame('PUBLISHED', $body['status']);

        // clear() vide l'identity map de Doctrine : le studio est relu en base,
        // et non repris de l'objet déjà chargé en mémoire.
        $em->clear();
        $refreshedStudio = $em->getRepository(Studio::class)->find($studio->getId());
        $this->assertTrue($refreshedStudio->isValidated());
    }

    /**
     * Approuver un film déjà PUBLISHED (studio validé, rien en attente) est
     * refusé : 409 Conflict, seul un contenu en PENDING_APPROVAL s'approuve.
     */
    public function testApproveFilmAlreadyPublishedReturns409(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [, $studio, ] = $this->createCreatorWithStudio();
        $film = $this->createFilmInStudio($em, $studio, 'Déjà publié', Film::STATUS_PUBLISHED);

        $adminToken = $this->createAdminToken();

        $response = $this->patchJson(
            $client,
            '/api/admin/films/' . $film->getId()->toRfc4122() . '/approve',
            [],
            $adminToken,
        );
        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    /**
     * Refus d'un film en attente : 200, le film repasse en DRAFT (modifiable et
     * resoumettable par le studio) et, en base, le studio reste non validé.
     */
    public function testRejectFilmReturnsToDraft(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [, $studio, ] = $this->createCreatorWithStudio();
        $studio->setIsValidated(false);
        $em->flush();

        $film = $this->createFilmInStudio($em, $studio, 'À refuser', Film::STATUS_PENDING_APPROVAL);

        $adminToken = $this->createAdminToken();

        // Le motif `reason` est accepté mais ni lu ni enregistré par le contrôleur
        // (aucune entité dédiée) : le test ne peut donc pas le vérifier.
        $response = $this->patchJson(
            $client,
            '/api/admin/films/' . $film->getId()->toRfc4122() . '/reject',
            ['reason' => 'Métadonnées insuffisantes.'],
            $adminToken,
        );
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);
        $this->assertSame('DRAFT', $body['status']);

        $em->clear();
        $refreshedStudio = $em->getRepository(Studio::class)->find($studio->getId());
        $this->assertFalse(
            $refreshedStudio->isValidated(),
            'Le refus ne doit pas valider le studio.',
        );
    }

    /**
     * Même règle pour une série : approuver une série en attente la publie
     * (200, PUBLISHED) et valide son studio en base.
     */
    public function testApproveSerieAlsoValidatesStudio(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [, $studio, ] = $this->createCreatorWithStudio();
        $studio->setIsValidated(false);
        $em->flush();

        $serie = $this->createSerieInStudio($em, $studio, 'Série à approuver', Serie::STATUS_PENDING_APPROVAL);

        $adminToken = $this->createAdminToken();

        $response = $this->patchJson(
            $client,
            '/api/admin/series/' . $serie->getId()->toRfc4122() . '/approve',
            [],
            $adminToken,
        );
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);
        $this->assertSame('PUBLISHED', $body['status']);

        $em->clear();
        $refreshedStudio = $em->getRepository(Studio::class)->find($studio->getId());
        $this->assertTrue($refreshedStudio->isValidated());
    }

    /**
     * Le créateur (ROLE_CREATEUR) ne peut pas approuver, même son propre film :
     * 403, ROLE_ADMIN étant exigé (`access_control` `^/api/admin` + `#[IsGranted]`).
     */
    public function testNonAdminCannotApprove403(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [, $studio, $creatorToken] = $this->createCreatorWithStudio();
        $film = $this->createFilmInStudio($em, $studio, 'Privé', Film::STATUS_PENDING_APPROVAL);

        $response = $this->patchJson(
            $client,
            '/api/admin/films/' . $film->getId()->toRfc4122() . '/approve',
            [],
            $creatorToken,
        );
        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Persiste un administrateur (ROLE_ADMIN, email unique) et renvoie son JWT,
     * forgé directement par JWTTokenManager (sans passer par `/api/auth/login`).
     */
    private function createAdminToken(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        /** @var JWTTokenManagerInterface $jwt */
        $jwt = static::getContainer()->get(JWTTokenManagerInterface::class);

        $admin = new User();
        $admin->setEmail('admin_' . Uuid::v4()->toRfc4122() . '@cinaf-test.com');
        $admin->setFirstName('Approval');
        $admin->setLastName('Admin');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setIsVerified(true);
        $admin->setPassword($hasher->hashPassword($admin, 'Password123!'));
        $em->persist($admin);
        $em->flush();

        return $jwt->create($admin);
    }

    /**
     * Persiste un film du studio donné directement au statut voulu (setStatus(),
     * sans ContentLifecycleService) : c'est ce qui permet de préparer un film
     * PENDING_APPROVAL ou PUBLISHED. Slug = titre (espaces → tirets) + suffixe
     * aléatoire, pour rester unique dans une base jamais vidée entre les tests.
     */
    private function createFilmInStudio(
        EntityManagerInterface $em,
        Studio $studio,
        string $title,
        string $status,
    ): Film {
        $film = new Film();
        $film->setTitle($title);
        $film->setSlug(strtolower(str_replace(' ', '-', $title)) . '-' . bin2hex(random_bytes(3)));
        $film->setSynopsis('Synopsis.');
        $film->setYear(2024);
        $film->setDuration(90);
        $film->setStudio($studio);
        $film->setStatus($status);
        $em->persist($film);
        $em->flush();

        return $film;
    }

    /** Équivalent de createFilmInStudio() pour une série (sans saison ni épisode). */
    private function createSerieInStudio(
        EntityManagerInterface $em,
        Studio $studio,
        string $title,
        string $status,
    ): Serie {
        $serie = new Serie();
        $serie->setTitle($title);
        $serie->setSlug(strtolower(str_replace(' ', '-', $title)) . '-' . bin2hex(random_bytes(3)));
        $serie->setSynopsis('Synopsis série.');
        $serie->setYear(2024);
        $serie->setStudio($studio);
        $serie->setStatus($status);
        $em->persist($serie);
        $em->flush();

        return $serie;
    }
}
