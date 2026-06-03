<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private UserPasswordHasherInterface $hasher,
        #[Autowire(service: 'kernel')]
        private KernelInterface $kernel,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // Admin de dev uniquement en environnement dev/test
        if (!\in_array($this->kernel->getEnvironment(), ['dev', 'test'], true)) {
            return;
        }

        $this->loadAdminUser($manager);

        $manager->flush();
    }

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
