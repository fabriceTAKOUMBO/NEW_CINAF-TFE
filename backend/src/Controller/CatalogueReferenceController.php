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

class CatalogueReferenceController extends AbstractController
{
    public function __construct(
        private GenreRepository $genres,
        private CountryRepository $countries,
        private LanguageRepository $languages,
        private PersonRepository $persons,
    ) {}

    #[Route('/api/genres', name: 'genres_list', methods: ['GET'])]
    public function listGenres(): JsonResponse
    {
        $data = array_map(fn(Genre $g) => $g->toArray(), $this->genres->findBy([], ['name' => 'ASC']));
        return new JsonResponse($data);
    }

    #[Route('/api/countries', name: 'countries_list', methods: ['GET'])]
    public function listCountries(): JsonResponse
    {
        $data = array_map(fn(Country $c) => $c->toArray(), $this->countries->findBy([], ['name' => 'ASC']));
        return new JsonResponse($data);
    }

    #[Route('/api/languages', name: 'languages_list', methods: ['GET'])]
    public function listLanguages(): JsonResponse
    {
        $data = array_map(fn(Language $l) => $l->toArray(), $this->languages->findBy([], ['name' => 'ASC']));
        return new JsonResponse($data);
    }

    #[Route('/api/persons', name: 'persons_list', methods: ['GET'])]
    public function listPersons(): JsonResponse
    {
        $data = array_map(fn(Person $p) => $p->toArray(), $this->persons->findBy([], ['lastName' => 'ASC']));
        return new JsonResponse($data);
    }
}
