<?php

namespace App\Tests\Admin\Controller;

use App\Entity\Film;
use App\Entity\Studio;
use App\Tests\Studio\Support\StudioTestTrait;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;

/**
 * Tests fonctionnels pour /api/admin/films (Phase C).
 *
 * Contrôleur testé : App\Admin\Controller\AdminFilmController. L'admin gère
 * les films de TOUS les studios, sans contrôle de propriété : liste paginée
 * `{data, total, page, limit}` filtrable par statut, studio et titre, fiche
 * détaillée, modification (titre, statut, rattachement à un autre studio) et
 * suppression définitive. Cas d'erreur couverts : 403 pour un non-admin,
 * 400 pour un identifiant qui n'est pas un UUID, 404 pour un film inconnu.
 *
 * Données : chaque test crée son admin (createAuthenticatedClient()), un ou
 * deux studios (StudioTestTrait) et ses films (createFilm()). La base n'étant
 * pas vidée entre les tests, les assertions portent sur les films créés par le
 * test (titres dédiés, jeton de recherche unique), pas sur toute la liste.
 *
 * Lancement : `php bin/phpunit tests/Admin/Controller/AdminFilmControllerTest.php`.
 */
class AdminFilmControllerTest extends ApiTestCase
{
    use StudioTestTrait;

    /**
     * Persiste un film du studio donné, DRAFT par défaut ; `publishedAt` est
     * renseigné si le statut demandé est PUBLISHED. Slug généré par AsciiSlugger
     * à partir du titre + suffixe aléatoire, pour rester unique. À appeler après
     * createAuthenticatedClient() (kernel déjà démarré).
     */
    private function createFilm(Studio $studio, string $title, string $status = Film::STATUS_DRAFT): Film
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $slugger = new AsciiSlugger();

        $film = new Film();
        $film->setTitle($title);
        $film->setSlug(strtolower((string) $slugger->slug($title . '-' . bin2hex(random_bytes(3)))));
        $film->setSynopsis('Synopsis de ' . $title);
        $film->setYear(2024);
        $film->setDuration(95);
        $film->setStudio($studio);
        $film->setStatus($status);
        if ($status === Film::STATUS_PUBLISHED) {
            $film->setPublishedAt(new \DateTimeImmutable());
        }
        $em->persist($film);
        $em->flush();

        return $film;
    }

    /**
     * L'admin voit les films de tous les studios : ceux des studios A et B
     * figurent dans la même liste (200, total ≥ 3).
     */
    public function test_admin_lists_all_films_across_studios(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [, $studioA] = $this->createCreatorWithStudio();
        [, $studioB] = $this->createOtherCreatorWithStudio();
        $this->createFilm($studioA, 'Cross-A1');
        $this->createFilm($studioA, 'Cross-A2');
        $this->createFilm($studioB, 'Cross-B1');

        // Liste triée du plus récent au plus ancien : les trois films qu'on vient
        // de créer sont en première page.
        $response = $this->getJson($client, '/api/admin/films?page=1&limit=50', $token);
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $titles = array_column($body['data'], 'title');
        $this->assertContains('Cross-A1', $titles);
        $this->assertContains('Cross-A2', $titles);
        $this->assertContains('Cross-B1', $titles);
        $this->assertGreaterThanOrEqual(3, $body['total']);
    }

    /**
     * Filtre `?status=PUBLISHED` : chaque film renvoyé est PUBLISHED (200).
     * Seul le filtrage est vérifié, pas la présence du film publié créé ici.
     */
    public function test_admin_filters_by_status(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [, $studio] = $this->createCreatorWithStudio();
        $this->createFilm($studio, 'Status-Draft', Film::STATUS_DRAFT);
        $this->createFilm($studio, 'Status-Published', Film::STATUS_PUBLISHED);

        $response = $this->getJson($client, '/api/admin/films?status=PUBLISHED&limit=50', $token);
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        foreach ($body['data'] as $row) {
            $this->assertSame('PUBLISHED', $row['status']);
        }
    }

    /**
     * Filtre `?studioId=` : seuls les films du studio A sont renvoyés (200), le
     * film du studio B est absent.
     */
    public function test_admin_filters_by_studio(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [, $studioA] = $this->createCreatorWithStudio();
        [, $studioB] = $this->createOtherCreatorWithStudio();
        $this->createFilm($studioA, 'StudioA-Only');
        $this->createFilm($studioB, 'StudioB-Only');

        $response = $this->getJson(
            $client,
            '/api/admin/films?studioId=' . $studioA->getId()->toRfc4122() . '&limit=50',
            $token,
        );
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        foreach ($body['data'] as $row) {
            $this->assertSame($studioA->getId()->toRfc4122(), $row['studioId']);
        }
        $titles = array_column($body['data'], 'title');
        $this->assertContains('StudioA-Only', $titles);
        $this->assertNotContains('StudioB-Only', $titles);
    }

    /**
     * Recherche `?search=` sur le titre : le jeton unique (suffixe aléatoire) ne
     * correspond qu'au film créé ici, d'où `total = 1` et le bon titre (200).
     */
    public function test_admin_searches_by_title(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [, $studio] = $this->createCreatorWithStudio();
        $unique = 'UniqueSearchToken' . bin2hex(random_bytes(2));
        $this->createFilm($studio, $unique);
        $this->createFilm($studio, 'Other Title');

        $response = $this->getJson($client, '/api/admin/films?search=' . urlencode($unique), $token);
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $this->assertSame(1, $body['total']);
        $this->assertSame($unique, $body['data'][0]['title']);
    }

    /**
     * Fiche détaillée `GET /api/admin/films/{id}` : 200, avec le titre et le
     * résumé du studio propriétaire (`studio.id`).
     */
    public function test_admin_get_film_detail(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [, $studio] = $this->createCreatorWithStudio();
        $film = $this->createFilm($studio, 'Detail-Film');

        $response = $this->getJson($client, '/api/admin/films/' . $film->getId()->toRfc4122(), $token);
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $this->assertSame('Detail-Film', $body['title']);
        $this->assertArrayHasKey('studio', $body);
        $this->assertSame($studio->getId()->toRfc4122(), $body['studio']['id']);
    }

    /**
     * PATCH admin d'un film DRAFT du studio A : nouveau titre, passage en
     * PUBLISHED et rattachement au studio B en une seule requête (200). Le
     * passage manuel en PUBLISHED renseigne `publishedAt`.
     */
    public function test_admin_patches_film(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [, $studioA] = $this->createCreatorWithStudio();
        [, $studioB] = $this->createOtherCreatorWithStudio();
        $film = $this->createFilm($studioA, 'Pre-Edit');

        $response = $this->patchJson(
            $client,
            '/api/admin/films/' . $film->getId()->toRfc4122(),
            [
                'title' => 'Post-Edit',
                'status' => 'PUBLISHED',
                'studioId' => $studioB->getId()->toRfc4122(),
            ],
            $token,
        );
        $body = $this->assertJsonResponse($response, Response::HTTP_OK);

        $this->assertSame('Post-Edit', $body['title']);
        $this->assertSame('PUBLISHED', $body['status']);
        $this->assertSame($studioB->getId()->toRfc4122(), $body['studioId']);
        $this->assertNotNull($body['publishedAt']);
    }

    /**
     * Suppression définitive `DELETE /api/admin/films/{id}` : 204 sans contenu,
     * puis le film est introuvable en base.
     */
    public function test_admin_deletes_film(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        [, $studio] = $this->createCreatorWithStudio();
        $film = $this->createFilm($studio, 'To-Delete');
        $filmId = $film->getId();

        $client->request(
            'DELETE',
            '/api/admin/films/' . $filmId->toRfc4122(),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );
        $this->assertSame(204, $client->getResponse()->getStatusCode());

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        // clear() vide l'identity map de Doctrine : find() interroge vraiment la
        // base, où le film ne doit plus exister.
        $em->clear();
        $reloaded = $em->getRepository(Film::class)->find($filmId);
        $this->assertNull($reloaded);
    }

    /** Un utilisateur ROLE_USER n'accède pas à la liste admin des films : 403. */
    public function test_non_admin_403(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_USER');
        $response = $this->getJson($client, '/api/admin/films', $token);
        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    /** Identifiant qui n'est pas un UUID (`not-a-uuid`) : 400, sans recherche en base. */
    public function test_invalid_uuid_400(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        $response = $this->getJson($client, '/api/admin/films/not-a-uuid', $token);
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    /** UUID bien formé mais absent de la base : 404. */
    public function test_film_not_found_404(): void
    {
        [$client, $token] = $this->createAuthenticatedClient('ROLE_ADMIN');
        $response = $this->getJson($client, '/api/admin/films/' . Uuid::v4()->toRfc4122(), $token);
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }
}
