<?php

/**
 * Bundles Symfony activés, avec les environnements où ils le sont
 * (`all`, `dev`, `test`). Lu par le Kernel (MicroKernelTrait).
 *
 * À noter : MakerBundle (générateurs de code) n'existe qu'en dev ;
 * DoctrineFixturesBundle qu'en dev et test, les fixtures (comptes de démo)
 * ne peuvent donc pas être chargées en production. FlysystemBundle reste
 * chargé bien que son stockage ne soit plus utilisé (cf. flysystem.yaml).
 */
return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    ApiPlatform\Symfony\Bundle\ApiPlatformBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle::class => ['all' => true],
    Nelmio\CorsBundle\NelmioCorsBundle::class => ['all' => true],
    Symfony\Bundle\MakerBundle\MakerBundle::class => ['dev' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true],
    Lexik\Bundle\JWTAuthenticationBundle\LexikJWTAuthenticationBundle::class => ['all' => true],
    Gesdinet\JWTRefreshTokenBundle\GesdinetJWTRefreshTokenBundle::class => ['all' => true],
    League\FlysystemBundle\FlysystemBundle::class => ['all' => true],
    Doctrine\Bundle\FixturesBundle\DoctrineFixturesBundle::class => ['dev' => true, 'test' => true],
];
