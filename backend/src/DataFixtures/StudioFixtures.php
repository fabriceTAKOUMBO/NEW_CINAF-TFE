<?php

namespace App\DataFixtures;

use App\Entity\Studio;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Phase A — Producer module foundation:
 * Seed 10 fictional studios + their owner users (ROLE_CREATEUR).
 *
 * Idempotent: each user/studio is checked by email/slug before creation,
 * so running the loader multiple times (with --append) is safe.
 *
 * Only runs in `dev` and `test` environments — never in `prod`.
 */
class StudioFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    /**
     * Canonical seed list — must match exactly across all agents (1..6).
     *
     * @var list<array{name: string, slug: string, email: string, bunnyFolder: string}>
     */
    private const STUDIOS = [
        ['name' => 'Nollywood Studios',     'slug' => 'nollywood-studios',     'email' => 'nollywood@cinaf.com',  'bunnyFolder' => 'studios/nollywood-studios/'],
        ['name' => 'Ouaga Films',           'slug' => 'ouaga-films',           'email' => 'ouaga@cinaf.com',      'bunnyFolder' => 'studios/ouaga-films/'],
        ['name' => 'Dakar Productions',     'slug' => 'dakar-productions',     'email' => 'dakar@cinaf.com',      'bunnyFolder' => 'studios/dakar-productions/'],
        ['name' => 'Cinéma du Sahel',       'slug' => 'cinema-du-sahel',       'email' => 'sahel@cinaf.com',      'bunnyFolder' => 'studios/cinema-du-sahel/'],
        ['name' => 'Kigali Pictures',       'slug' => 'kigali-pictures',       'email' => 'kigali@cinaf.com',     'bunnyFolder' => 'studios/kigali-pictures/'],
        ['name' => 'Maputo Vision',         'slug' => 'maputo-vision',         'email' => 'maputo@cinaf.com',     'bunnyFolder' => 'studios/maputo-vision/'],
        ['name' => 'Abidjan Movies',        'slug' => 'abidjan-movies',        'email' => 'abidjan@cinaf.com',    'bunnyFolder' => 'studios/abidjan-movies/'],
        ['name' => 'Casablanca Films',      'slug' => 'casablanca-films',      'email' => 'casablanca@cinaf.com', 'bunnyFolder' => 'studios/casablanca-films/'],
        ['name' => 'Nairobi Studios',       'slug' => 'nairobi-studios',       'email' => 'nairobi@cinaf.com',    'bunnyFolder' => 'studios/nairobi-studios/'],
        ['name' => 'Yaoundé Productions',   'slug' => 'yaounde-productions',   'email' => 'yaounde@cinaf.com',    'bunnyFolder' => 'studios/yaounde-productions/'],
    ];

    public function __construct(
        private UserPasswordHasherInterface $hasher,
        #[Autowire(service: 'kernel')]
        private KernelInterface $kernel,
    ) {
    }

    public static function getGroups(): array
    {
        return ['producer', 'studio'];
    }

    public function getDependencies(): array
    {
        return [AppFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        // Producer fixtures only in dev/test (parity with AppFixtures admin).
        if (!\in_array($this->kernel->getEnvironment(), ['dev', 'test'], true)) {
            return;
        }

        $userRepo = $manager->getRepository(User::class);
        $studioRepo = $manager->getRepository(Studio::class);

        foreach (self::STUDIOS as $row) {
            // Skip if already seeded (idempotent).
            if ($userRepo->findOneBy(['email' => $row['email']]) !== null) {
                continue;
            }
            if ($studioRepo->findOneBy(['slug' => $row['slug']]) !== null) {
                continue;
            }

            // 1. Create the producer user (ROLE_CREATEUR).
            $user = new User();
            $user->setEmail($row['email']);
            $user->setFirstName('Producer');
            $user->setLastName($row['name']);
            $user->setRoles(['ROLE_CREATEUR']);
            $user->setIsVerified(true);
            $user->setIsSuspended(false);
            $user->setConsentRgpd(true);
            $user->setPassword($this->hasher->hashPassword($user, 'Producer1234!'));
            $manager->persist($user);

            // 2. Create the studio with this user as owner.
            $studio = new Studio();
            $studio->setName($row['name']);
            $studio->setSlug($row['slug']);
            $studio->setOwner($user);
            $studio->setDescription('Studio de production basé en Afrique');
            $studio->setBunnyFolder($row['bunnyFolder']);
            $studio->setIsActive(true);
            // Studios fictifs : assimilés à des studios "historiques", déjà
            // validés implicitement. Sans cela, leurs premiers publish dans
            // les tests E2E partiraient en PENDING_APPROVAL et casseraient
            // la baseline existante (non-régression).
            $studio->setIsValidated(true);
            $manager->persist($studio);
        }

        $manager->flush();
    }
}
