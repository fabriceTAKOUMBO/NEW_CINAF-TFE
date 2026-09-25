<?php
namespace App\Controller;

use App\Entity\Country;
use App\Entity\Genre;
use App\Entity\Language;
use App\Entity\Person;
use App\Repository\CountryRepository;
use App\Repository\GenreRepository;
use App\Repository\LanguageRepository;
use App\Repository\PersonRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Référentiels du catalogue, en lecture seule : genres, pays, langues et
 * personnes (réalisateurs / acteurs).
 *
 * Endpoints publics (ni `#[IsGranted]` ni règle access_control) :
 *  - GET /api/genres     tous les genres, triés par nom ;
 *  - GET /api/countries  tous les pays, triés par nom ;
 *  - GET /api/languages  toutes les langues, triées par nom ;
 *  - GET /api/persons    toutes les personnes, triées par nom de famille.
 *
 * Chaque réponse est un tableau JSON plat (200, sans pagination ni enveloppe)
 * des toArray() de l'entité. Aucun endpoint n'écrit dans ces référentiels.
 */
class CatalogueReferenceController extends AbstractController
{
    public function __construct(
        private GenreRepository $genres,
        private CountryRepository $countries,
        private LanguageRepository $languages,
        private PersonRepository $persons,
    ) {}

    /**
     * Liste des genres.
     *
     * @return JsonResponse 200 tableau de Genre::toArray() (`{id, name, slug}`), trié par nom
     */
    #[Route('/api/genres', name: 'genres_list', methods: ['GET'])]
    public function listGenres(): JsonResponse
    {
        $data = array_map(fn(Genre $g) => $g->toArray(), $this->genres->findBy([], ['name' => 'ASC']));
        return new JsonResponse($data);
    }

    /**
     * Liste des pays.
     *
     * @return JsonResponse 200 tableau de Country::toArray() (`{id, name, isoCode}`), trié par nom
     */
    #[Route('/api/countries', name: 'countries_list', methods: ['GET'])]
    public function listCountries(): JsonResponse
    {
        $data = array_map(fn(Country $c) => $c->toArray(), $this->countries->findBy([], ['name' => 'ASC']));
        return new JsonResponse($data);
    }

    /**
     * Liste des langues.
     *
     * @return JsonResponse 200 tableau de Language::toArray() (`{id, name, isoCode}`), trié par nom
     */
    #[Route('/api/languages', name: 'languages_list', methods: ['GET'])]
    public function listLanguages(): JsonResponse
    {
        $data = array_map(fn(Language $l) => $l->toArray(), $this->languages->findBy([], ['name' => 'ASC']));
        return new JsonResponse($data);
    }

    /**
     * Liste des personnes (réalisateurs et acteurs confondus : le rôle dépend
     * du film, pas de la personne).
     *
     * @return JsonResponse 200 tableau de Person::toArray() (`{id, firstName, lastName,
     *                      photo, biography, birthDate}`), trié par nom de famille
     */
    #[Route('/api/persons', name: 'persons_list', methods: ['GET'])]
    public function listPersons(): JsonResponse
    {
        $data = array_map(fn(Person $p) => $p->toArray(), $this->persons->findBy([], ['lastName' => 'ASC']));
        return new JsonResponse($data);
    }
}
