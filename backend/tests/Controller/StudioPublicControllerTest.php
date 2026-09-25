<?php

namespace App\Tests\Controller;

use App\Entity\Film;
use App\Entity\Serie;
use App\Entity\Studio;
use App\Tests\Studio\Support\StudioTestTrait;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests fonctionnels des endpoints publics « chaîne studio ».
 *
 * Un studio n'est exposé publiquement que s'il a `isActive=true`,
 * `isValidated=true` ET au moins un Film ou Serie en `status='PUBLISHED'`.
 * Tous les autres cas mappent vers 404 (pas 403) pour ne pas leaker
 * l'existence de studios non publics.
 *
 * Couvre la liste (`/api/studios`), la fiche (`/{slug}`), les œuvres
 * (`/{slug}/works`) et la recherche (`/search`). Les studios sont créés par
 * StudioTestTrait::createCreatorWithStudio() (actifs et validés, slugs
 * uniques) dans la base de test, sans appel réseau.
 *
 * Lancement : `php bin/phpunit tests/Controller/StudioPublicControllerTest.php`.
 */
final class StudioPublicControllerTest extends ApiTestCase
{
    use StudioTestTrait;

    /** Un studio avec un film publié figure dans la liste ; un studio sans contenu n'y figure pas. */
    public function testListReturnsOnlyStudiosWithPublishedContent(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // Studio A — un film PUBLISHED → doit apparaître.
        [, $studioA] = $this->createCreatorWithStudio('listed-a-' . bin2hex(random_bytes(3)));
        $this->seedFilm($em, $studioA, Film::STATUS_PUBLISHED);

        // Studio B — aucun contenu → doit être absent.
        [, $studioB] = $this->createCreatorWithStudio('listed-b-' . bin2hex(random_bytes(3)));

        $resp = $this->getJson($client, '/api/studios?itemsPerPage=100');
        $body = $this->assertJsonResponse($resp, Response::HTTP_OK);

        $names = array_column($body['hydra:member'], 'name');
        $this->assertContains($studioA->getName(), $names);
        $this->assertNotContains($studioB->getName(), $names);
    }

    /** Des studios qui n'ont que du DRAFT ou du WITHDRAWN sont absents de la liste. */
    public function testListExcludesStudiosWithDraftOrWithdrawnOnly(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [, $studioDraft] = $this->createCreatorWithStudio('draft-' . bin2hex(random_bytes(3)));
        $this->seedFilm($em, $studioDraft, Film::STATUS_DRAFT);

        [, $studioWithdrawn] = $this->createCreatorWithStudio('with-' . bin2hex(random_bytes(3)));
        $this->seedFilm($em, $studioWithdrawn, Film::STATUS_WITHDRAWN);

        $resp = $this->getJson($client, '/api/studios?itemsPerPage=100');
        $body = $this->assertJsonResponse($resp, Response::HTTP_OK);

        $names = array_column($body['hydra:member'], 'name');
        $this->assertNotContains($studioDraft->getName(), $names);
        $this->assertNotContains($studioWithdrawn->getName(), $names);
    }

    /** Fiche studio : compteurs limités au contenu publié (2 films, 1 série) et `ownerId` masqué. */
    public function testGetByPublicSlugReturnsStudioWithCounts(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [, $studio] = $this->createCreatorWithStudio('counts-' . bin2hex(random_bytes(3)));
        // 2 films PUBLISHED + 1 DRAFT (le draft ne doit pas être compté) + 1 serie PUBLISHED.
        $this->seedFilm($em, $studio, Film::STATUS_PUBLISHED);
        $this->seedFilm($em, $studio, Film::STATUS_PUBLISHED);
        $this->seedFilm($em, $studio, Film::STATUS_DRAFT);
        $this->seedSerie($em, $studio, Serie::STATUS_PUBLISHED);

        $resp = $this->getJson($client, '/api/studios/' . $studio->getSlug());
        $body = $this->assertJsonResponse($resp, Response::HTTP_OK);

        $this->assertSame($studio->getName(), $body['name']);
        $this->assertSame(2, $body['publishedFilmsCount']);
        $this->assertSame(1, $body['publishedSeriesCount']);
        // L'ownerId est une donnée privée : il ne doit pas fuiter publiquement.
        $this->assertArrayNotHasKey('ownerId', $body);
    }

    /** Fiche d'un studio existant mais sans contenu publié : 404. */
    public function testGetByPublicSlugReturns404IfNoPublishedContent(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [, $studio] = $this->createCreatorWithStudio('hidden-' . bin2hex(random_bytes(3)));
        // Studio existe mais n'a que du DRAFT → invisible publiquement.
        $this->seedFilm($em, $studio, Film::STATUS_DRAFT);

        $resp = $this->getJson($client, '/api/studios/' . $studio->getSlug());
        $this->assertSame(Response::HTTP_NOT_FOUND, $resp->getStatusCode());
    }

    /** `/works` ne renvoie que le film et la série publiés (ni brouillon ni retiré), chacun avec son `kind`. */
    public function testGetWorksReturnsOnlyPublishedFilmsAndSeries(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [, $studio] = $this->createCreatorWithStudio('works-' . bin2hex(random_bytes(3)));
        $publishedFilm = $this->seedFilm($em, $studio, Film::STATUS_PUBLISHED);
        $publishedSerie = $this->seedSerie($em, $studio, Serie::STATUS_PUBLISHED);
        // Ces deux contenus ne doivent PAS apparaître :
        $draftFilm = $this->seedFilm($em, $studio, Film::STATUS_DRAFT);
        $withdrawnSerie = $this->seedSerie($em, $studio, Serie::STATUS_WITHDRAWN);

        $resp = $this->getJson($client, '/api/studios/' . $studio->getSlug() . '/works');
        $body = $this->assertJsonResponse($resp, Response::HTTP_OK);

        $ids = array_column($body['hydra:member'], 'id');
        $this->assertContains($publishedFilm->getId()->toRfc4122(), $ids);
        $this->assertContains($publishedSerie->getId()->toRfc4122(), $ids);
        $this->assertNotContains($draftFilm->getId()->toRfc4122(), $ids);
        $this->assertNotContains($withdrawnSerie->getId()->toRfc4122(), $ids);

        // kind est bien renseigné sur chaque œuvre.
        foreach ($body['hydra:member'] as $work) {
            $this->assertContains($work['kind'], ['film', 'serie']);
        }
    }

    /** La recherche par nom trouve le studio public et exclut un studio dont le nom contient aussi le terme mais sans contenu publié. */
    public function testSearchByNameReturnsMatchingPublicStudios(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // Token unique pour cette recherche, embarqué dans le nom du studio.
        $needle = 'searchneedle' . bin2hex(random_bytes(3));

        [, $matching] = $this->createCreatorWithStudio($needle);
        $this->seedFilm($em, $matching, Film::STATUS_PUBLISHED);

        // Studio "non public" portant aussi le needle : doit être exclu.
        [, $hiddenWithNeedle] = $this->createCreatorWithStudio($needle . '-hidden');
        $this->seedFilm($em, $hiddenWithNeedle, Film::STATUS_DRAFT);

        $resp = $this->getJson($client, '/api/studios/search?q=' . $needle);
        $body = $this->assertJsonResponse($resp, Response::HTTP_OK);

        $this->assertIsArray($body);
        $names = array_column($body, 'name');
        $this->assertContains($matching->getName(), $names);
        $this->assertNotContains($hiddenWithNeedle->getName(), $names);
    }

    /** Recherche avec un seul caractère : 200 et tableau vide. */
    public function testSearchReturnsEmptyArrayWhenQueryTooShort(): void
    {
        $client = static::createClient();

        // 1 caractère = sous le seuil min 2 → tableau vide, pas une erreur.
        $resp = $this->getJson($client, '/api/studios/search?q=a');
        $body = $this->assertJsonResponse($resp, Response::HTTP_OK);
        $this->assertSame([], $body);
    }

    // ----------------------------------------------------------------
    // Helpers internes — création de contenu pour les tests.
    // ----------------------------------------------------------------

    /** Crée un film au statut donné, avec titre et slug uniques, rattaché au studio. */
    private function seedFilm(EntityManagerInterface $em, Studio $studio, string $status): Film
    {
        $uid = bin2hex(random_bytes(4));
        $film = new Film();
        $film->setTitle('Film Test ' . $uid);
        $film->setSlug('film-test-' . $uid);
        $film->setSynopsis('Synopsis de test.');
        $film->setYear(2024);
        $film->setDuration(95);
        $film->setStudio($studio);
        $film->setStatus($status);
        $em->persist($film);
        $em->flush();
        return $film;
    }

    /** Crée une série au statut donné, avec titre et slug uniques, rattachée au studio. */
    private function seedSerie(EntityManagerInterface $em, Studio $studio, string $status): Serie
    {
        $uid = bin2hex(random_bytes(4));
        $serie = new Serie();
        $serie->setTitle('Serie Test ' . $uid);
        $serie->setSlug('serie-test-' . $uid);
        $serie->setSynopsis('Synopsis de test.');
        $serie->setYear(2024);
        $serie->setStudio($studio);
        $serie->setStatus($status);
        $em->persist($serie);
        $em->flush();
        return $serie;
    }
}
