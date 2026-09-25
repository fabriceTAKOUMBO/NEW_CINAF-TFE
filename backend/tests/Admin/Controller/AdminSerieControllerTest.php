<?php

namespace App\Tests\Admin\Controller;

use App\Entity\Serie;
use App\Entity\Studio;
use App\Tests\Studio\Support\StudioTestTrait;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Tests fonctionnels pour /api/admin/series (Phase C).
 *
 * Contrôleur testé : App\Admin\Controller\AdminSerieController, pendant de
 * AdminFilmController pour les séries : liste de toutes les séries (tous
 * studios confondus), filtre par statut, fiche détaillée avec résumé du
 * studio, modification (titre, statut) et suppression définitive ; 403 pour
 * un non-admin.
 *
 * Données : un admin (createAuthenticatedClient()), un ou deux studios
 * (StudioTestTrait) et des séries sans saison créées par createSerie().
 *
 * Lancement : `php bin/phpunit tests/Admin/Controller/AdminSerieControllerTest.php`.
 */
class AdminSerieControllerTest extends ApiTestCase
{
    use StudioTestTrait;

    /**
     * Persiste une série (sans saison) du studio donné, DRAFT par défaut ;
     * `publishedAt` est renseigné si le statut demandé est PUBLISHED. Slug
     * AsciiSlugger (titre + suffixe aléatoire) pour rester unique.
     */
    private function createSerie(Studio $studio, string $title, string $status = Serie::STATUS_DRAFT): Serie
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $slugger = new AsciiSlugger();

        $serie = new Serie();
        $serie->setTitle($title);
        $serie->setSlug(strtolower((string) $slugger->slug($title . '-' . bin2hex(random_bytes(3)))));
        $serie->setSynopsis('Synopsis de ' . $title);
        $serie->setYear(2024);
        $serie->setStudio($studio);
        $serie->setStatus($status);
        if ($status === Serie::STATUS_PUBLISHED) {
            $serie->setPublishedAt(new \DateTimeImmutable());
        }
        $em->persist($serie);
        $em->flush();

        return $serie;
    }

    /**
     * L'admin voit les séries de tous les studios : celles des studios A et B
     * figurent dans la même liste (200).
     */
    public function test_admin_lists_all_series_across_studios(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [, $studioA] = $this->createCreatorWithStudio();
        [, $studioB] = $this->createOtherCreatorWithStudio();
        $this->createSerie($studioA, 'SerieCross-A1');
        $this->createSerie($studioB, 'SerieCross-B1');

        $response = $this->getJson($client, '/api/admin/series?limit=50', $token);
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $titles = array_column($body['data'], 'title');
        $this->assertContains('SerieCross-A1', $titles);
        $this->assertContains('SerieCross-B1', $titles);
    }

    /**
     * Filtre `?status=PUBLISHED` : chaque série renvoyée est PUBLISHED (200).
     * Seul le filtrage est vérifié, pas la présence de la série publiée créée ici.
     */
    public function test_admin_filters_serie_by_status(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [, $studio] = $this->createCreatorWithStudio();
        $this->createSerie($studio, 'SerieDraft', Serie::STATUS_DRAFT);
        $this->createSerie($studio, 'SeriePub', Serie::STATUS_PUBLISHED);

        $response = $this->getJson($client, '/api/admin/series?status=PUBLISHED&limit=50', $token);
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        foreach ($body['data'] as $row) {
            $this->assertSame('PUBLISHED', $row['status']);
        }
    }

    /**
     * Fiche détaillée `GET /api/admin/series/{id}` : 200, avec le titre et le
     * résumé du studio propriétaire (`studio.id`).
     */
    public function test_admin_get_serie_detail(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [, $studio] = $this->createCreatorWithStudio();
        $serie = $this->createSerie($studio, 'SerieDetail');

        $response = $this->getJson($client, '/api/admin/series/' . $serie->getId()->toRfc4122(), $token);
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $this->assertSame('SerieDetail', $body['title']);
        $this->assertArrayHasKey('studio', $body);
        $this->assertSame($studio->getId()->toRfc4122(), $body['studio']['id']);
    }

    /**
     * PATCH admin d'une série DRAFT : nouveau titre et passage en PUBLISHED en
     * une seule requête (200), la réponse reflétant les deux changements.
     */
    public function test_admin_patches_serie(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [, $studio] = $this->createCreatorWithStudio();
        $serie = $this->createSerie($studio, 'SeriePreEdit');

        $response = $this->patchJson(
            $client,
            '/api/admin/series/' . $serie->getId()->toRfc4122(),
            ['title' => 'SeriePostEdit', 'status' => 'PUBLISHED'],
            $token,
        );
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $this->assertSame('SeriePostEdit', $body['title']);
        $this->assertSame('PUBLISHED', $body['status']);
    }

    /**
     * Suppression définitive `DELETE /api/admin/series/{id}` : 204 sans contenu,
     * puis la série est introuvable en base.
     */
    public function test_admin_deletes_serie(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [, $studio] = $this->createCreatorWithStudio();
        $serie = $this->createSerie($studio, 'SerieToDelete');
        $serieId = $serie->getId();

        $client->request(
            'DELETE',
            '/api/admin/series/' . $serieId->toRfc4122(),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );
        $this->assertSame(204, $client->getResponse()->getStatusCode());

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        // clear() vide l'identity map : find() interroge vraiment la base.
        $em->clear();
        $reloaded = $em->getRepository(Serie::class)->find($serieId);
        $this->assertNull($reloaded);
    }

    /** Un utilisateur ROLE_USER n'accède pas à la liste admin des séries : 403. */
    public function test_admin_serie_non_admin_403(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_USER');
        $response = $this->getJson($client, '/api/admin/series', $token);
        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }
}
