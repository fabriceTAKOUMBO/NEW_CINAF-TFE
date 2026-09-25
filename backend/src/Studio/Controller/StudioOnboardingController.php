<?php

namespace App\Studio\Controller;

use App\Entity\Studio;
use App\Entity\User;
use App\Repository\StudioRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Parcours self-service "Je suis producteur" :
 *
 * Un utilisateur authentifié (ROLE_USER ou ROLE_ABONNE) peut créer
 * son propre studio en fournissant un nom et une description. À la
 * création, on lui ajoute `ROLE_CREATEUR` et on instancie le studio
 * avec `isValidated = false` — son premier contenu publié devra
 * être approuvé par un administrateur avant d'être visible.
 *
 * Refus :
 *   - 403 si l'appelant est ROLE_ADMIN (séparation stricte Phase H :
 *     un admin ne possède pas ROLE_CREATEUR, donc ne peut pas avoir
 *     de studio).
 *   - 409 si l'appelant possède déjà un studio (relation OneToOne).
 *   - 409 si le nom de studio est déjà pris (contrainte unique en DB).
 *   - 400 si name ou description sont absents/vides.
 *
 * NB : la règle access_control de `^/api/studio` impose ROLE_CREATEUR,
 * c'est pourquoi `security.yaml` doit déclarer une exception spécifique
 * `^/api/studio/onboarding` → IS_AUTHENTICATED_FULLY AVANT cette
 * règle générique (l'ordre compte).
 */
#[Route('/api/studio/onboarding')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class StudioOnboardingController extends AbstractController
{
    public function __construct(
        private readonly StudioRepository $studioRepo,
        private readonly EntityManagerInterface $em,
        private readonly SluggerInterface $slugger,
    ) {
    }

    /**
     * Crée le studio de l'utilisateur connecté et lui attribue ROLE_CREATEUR.
     *
     * Corps JSON : `name` et `description`, obligatoires et non vides après trim.
     * Le slug est dérivé du nom (rendu unique si besoin) et détermine le dossier
     * Bunny du studio, `studios/{slug}/`, sous lequel seront rangés tous ses uploads.
     *
     * @return JsonResponse 201 studio créé (`Studio::toArray()`, `isValidated` à false) ;
     *                      400 si le JSON est invalide ou si `name`/`description` est vide ;
     *                      403 si l'appelant est administrateur ; 409 s'il possède déjà
     *                      un studio ou si le nom est déjà pris
     */
    #[Route('', name: 'studio_onboarding_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        // Un administrateur n'a pas vocation à posséder un studio
        // (séparation stricte ROLE_ADMIN / ROLE_CREATEUR — Phase H).
        if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return new JsonResponse(
                ['message' => "Un administrateur ne peut pas créer de studio."],
                403,
            );
        }

        if ($user->getStudio() !== null) {
            return new JsonResponse(
                ['message' => 'Vous possédez déjà un studio.'],
                409,
            );
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['message' => 'Corps JSON invalide.'], 400);
        }

        $name = isset($data['name']) ? trim((string) $data['name']) : '';
        $description = isset($data['description']) ? trim((string) $data['description']) : '';

        if ($name === '') {
            return new JsonResponse(['message' => 'Le champ "name" est requis.'], 400);
        }
        if ($description === '') {
            return new JsonResponse(['message' => 'Le champ "description" est requis.'], 400);
        }

        // Pré-check applicatif : évite la levée d'exception côté DB
        // sur le cas courant (nom déjà pris). La contrainte unique reste
        // le filet de sécurité contre la race condition.
        $existing = $this->studioRepo->findOneBy(['name' => $name]);
        if ($existing !== null) {
            return new JsonResponse(
                ['message' => 'Ce nom de studio est déjà utilisé.'],
                409,
            );
        }

        // Slug dérivé du nom. Il peut être vide si le nom ne contient aucun
        // caractère translittérable (ponctuation, emoji...) : on retombe alors
        // sur un slug générique `studio-` + 6 caractères hexadécimaux aléatoires.
        $baseSlug = $this->slugger->slug($name)->lower()->toString();
        if ($baseSlug === '') {
            $baseSlug = 'studio-' . bin2hex(random_bytes(3));
        }
        $slug = $this->generateUniqueSlug($baseSlug);

        $studio = new Studio();
        $studio->setName($name);
        $studio->setSlug($slug);
        $studio->setDescription($description);
        $studio->setOwner($user);
        // Même préfixe que celui exigé par StudioOwnershipChecker::assertBunnyPathOwnership()
        // et produit par BunnyPathBuilder : `studios/{slug}/`.
        $studio->setBunnyFolder(sprintf('studios/%s/', $slug));
        $studio->setIsActive(true);
        // Studio non validé : son premier contenu publié passera en
        // PENDING_APPROVAL (ContentLifecycleService::publishFilm/publishSerie).
        $studio->setIsValidated(false);

        // Ajoute ROLE_CREATEUR sans en perdre les éventuels rôles existants.
        // Le rôle est effectif dès la requête suivante, même avec le JWT déjà
        // émis : le pare-feu recharge l'utilisateur (et donc ses rôles) depuis
        // la base à chaque requête.
        $roles = $user->getRoles();
        if (!\in_array('ROLE_CREATEUR', $roles, true)) {
            $roles[] = 'ROLE_CREATEUR';
            // setRoles ne doit pas dupliquer ROLE_USER (getRoles l'ajoute).
            $roles = array_values(array_unique(array_filter($roles, fn ($r) => $r !== 'ROLE_USER')));
            $user->setRoles($roles);
        }

        $this->em->persist($studio);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Race condition : un autre user a pris le nom (ou le slug)
            // entre le pré-check et le flush.
            return new JsonResponse(
                ['message' => 'Ce nom de studio est déjà utilisé.'],
                409,
            );
        }

        return new JsonResponse($studio->toArray(), 201);
    }

    /**
     * Génère un slug unique en suffixant aléatoirement si collision.
     *
     * Jusqu'à 5 essais avec un suffixe de 5 caractères hexadécimaux, puis un
     * dernier suffixe de 8 caractères renvoyé sans vérification. L'index unique
     * `studio.slug` reste le filet de sécurité (409 au flush dans create()).
     *
     * @param string $base slug dérivé du nom du studio
     *
     * @return string slug libre au moment de la vérification
     */
    private function generateUniqueSlug(string $base): string
    {
        if ($this->studioRepo->findOneBy(['slug' => $base]) === null) {
            return $base;
        }
        for ($i = 0; $i < 5; $i++) {
            $candidate = $base . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
            if ($this->studioRepo->findOneBy(['slug' => $candidate]) === null) {
                return $candidate;
            }
        }
        // Fallback ultime — extrêmement improbable.
        return $base . '-' . bin2hex(random_bytes(4));
    }
}
