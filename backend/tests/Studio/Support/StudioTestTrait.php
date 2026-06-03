<?php

namespace App\Tests\Studio\Support;

use App\Entity\Studio;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Mutualise les helpers spécifiques aux tests du bounded context Producer
 * (création de créateur + studio + token JWT).
 */
trait StudioTestTrait
{
    /**
     * @return array{0: User, 1: Studio, 2: string}
     */
    protected function createCreatorWithStudio(?string $slugSuffix = null): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        /** @var JWTTokenManagerInterface $jwt */
        $jwt = static::getContainer()->get(JWTTokenManagerInterface::class);

        $unique = $slugSuffix ?? bin2hex(random_bytes(4));

        $user = new User();
        $user->setEmail('creator_' . $unique . '@cinaf-test.com');
        $user->setFirstName('Test');
        $user->setLastName('Creator');
        $user->setRoles(['ROLE_CREATEUR']);
        $user->setIsVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'Password123!'));
        $em->persist($user);

        $studio = new Studio();
        $studio->setName('Test Studio ' . $unique);
        $studio->setSlug('test-studio-' . $unique);
        $studio->setOwner($user);
        $studio->setBunnyFolder('studios/test-studio-' . $unique . '/');
        $studio->setIsActive(true);
        // Trait utilisé par les tests studio "historiques" qui n'attendent
        // pas le workflow PENDING_APPROVAL : on force `isValidated = true`
        // pour préserver leur comportement (publish direct en PUBLISHED).
        // Les tests onboarding/approbation créent leur studio manuellement.
        $studio->setIsValidated(true);
        $em->persist($studio);

        $em->flush();

        return [$user, $studio, $jwt->create($user)];
    }

    /**
     * @return array{0: User, 1: Studio, 2: string}
     */
    protected function createOtherCreatorWithStudio(): array
    {
        return $this->createCreatorWithStudio('other_' . bin2hex(random_bytes(3)));
    }

    /**
     * Crée un user "lambda" (ROLE_USER) avec un token JWT.
     *
     * @return array{0: User, 1: string}
     */
    protected function createPlainUserWithToken(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        /** @var JWTTokenManagerInterface $jwt */
        $jwt = static::getContainer()->get(JWTTokenManagerInterface::class);

        $user = new User();
        $user->setEmail('user_' . Uuid::v4()->toRfc4122() . '@cinaf-test.com');
        $user->setFirstName('Plain');
        $user->setLastName('User');
        $user->setRoles(['ROLE_USER']);
        $user->setIsVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'Password123!'));
        $em->persist($user);
        $em->flush();

        return [$user, $jwt->create($user)];
    }
}
