<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Fixture de base : crée le compte administrateur de développement
 * `admin@cinaf.com` / `Admin1234!` (ROLE_ADMIN).
 *
 * Idempotente (compte recherché par email avant création) et limitée aux
 * environnements dev et test. StudioFixtures en dépend et est donc chargée
 * après elle. Chargement : `php bin/console doctrine:fixtures:load`
 * (ajouter `--append` pour ne pas purger la base au préalable).
 */
class AppFixtures extends Fixture
{
    /**
     * Le kernel est injecté explicitement (service `kernel`) uniquement pour
     * lire l'environnement courant dans load().
     */
    public function __construct(
        private UserPasswordHasherInterface $hasher,
        #[Autowire(service: 'kernel')]
        private KernelInterface $kernel,
    ) {
    }

    /**
     * Point d'entrée appelé par `doctrine:fixtures:load`.
     */
    public function load(ObjectManager $manager): void
    {
        // Admin de dev uniquement en environnement dev/test
        // (garde-fou supplémentaire : DoctrineFixturesBundle n'est de toute
        // façon enregistré qu'en dev et test, cf. config/bundles.php).
        if (!\in_array($this->kernel->getEnvironment(), ['dev', 'test'], true)) {
            return;
        }

        $this->loadAdminUser($manager);

        $manager->flush();
    }

    /**
     * Persiste le compte administrateur s'il n'existe pas encore (le flush est
     * fait par load()).
     *
     * Seul ROLE_ADMIN est stocké : la hiérarchie de security.yaml lui donne aussi
     * MODERATEUR, ABONNE et USER, mais pas ROLE_CREATEUR (un admin n'a pas de studio).
     * Le compte est créé avec un email déjà vérifié et non suspendu (un compte
     * suspendu serait refusé au login).
     */
    private function loadAdminUser(ObjectManager $manager): void
    {
        $existing = $manager->getRepository(User::class)->findOneBy(['email' => 'admin@cinaf.com']);
        if ($existing !== null) {
            return;
        }

        $admin = new User();
        $admin->setEmail('admin@cinaf.com');
        $admin->setFirstName('Admin');
        $admin->setLastName('CINAF');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setIsVerified(true);
        $admin->setIsSuspended(false);
        $admin->setConsentRgpd(true);
        $admin->setPassword($this->hasher->hashPassword($admin, 'Admin1234!'));

        $manager->persist($admin);
    }
}
