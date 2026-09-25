<?php

namespace App\Tests\Entity;

use App\Entity\Studio;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Pure unit tests for the Studio entity (no DB, no kernel boot).
 *
 * Tests unitaires de l'entité Studio, sans base ni kernel : valeurs par
 * défaut du constructeur, accesseurs et forme de toArray().
 *
 * Lancement : php bin/phpunit tests/Entity/StudioTest.php
 */
class StudioTest extends TestCase
{
    /**
     * Un studio neuf a un UUID et ses dates de création / modification, est
     * actif, sans description ni logo, et ne possède ni film ni série.
     */
    public function testConstructorInitializesDefaults(): void
    {
        $studio = new Studio();

        $this->assertInstanceOf(Uuid::class, $studio->getId());
        $this->assertInstanceOf(\DateTimeImmutable::class, $studio->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $studio->getUpdatedAt());
        $this->assertTrue($studio->isActive(), 'isActive defaults to true');
        $this->assertNull($studio->getDescription());
        $this->assertNull($studio->getLogoUrl());
        $this->assertCount(0, $studio->getFilms());
        $this->assertCount(0, $studio->getSeries());
    }

    /**
     * Les setters (chaînables) stockent chaque valeur, relue à l'identique par
     * le getter correspondant, y compris la désactivation (isActive = false).
     */
    public function testGettersAndSetters(): void
    {
        $owner = $this->makeOwner();
        $studio = new Studio();

        $studio
            ->setName('Nollywood Studios')
            ->setSlug('nollywood-studios')
            ->setOwner($owner)
            ->setDescription('Studio de production basé en Afrique')
            ->setLogoUrl('https://cdn.cinaf.com/studios/nollywood/logo.png')
            ->setBunnyFolder('studios/nollywood-studios/')
            ->setIsActive(false);

        $this->assertSame('Nollywood Studios', $studio->getName());
        $this->assertSame('nollywood-studios', $studio->getSlug());
        $this->assertSame($owner, $studio->getOwner());
        $this->assertSame('Studio de production basé en Afrique', $studio->getDescription());
        $this->assertSame('https://cdn.cinaf.com/studios/nollywood/logo.png', $studio->getLogoUrl());
        $this->assertSame('studios/nollywood-studios/', $studio->getBunnyFolder());
        $this->assertFalse($studio->isActive());
    }

    /**
     * Le slug est stocké tel quel. La regex ne fait que confirmer le format
     * kebab-case de la valeur d'exemple : l'entité ne valide pas ce format.
     */
    public function testSlugSetterAcceptsExpectedFormat(): void
    {
        $studio = new Studio();
        $studio->setSlug('cinema-du-sahel');

        $this->assertSame('cinema-du-sahel', $studio->getSlug());
        // Sanity: slug-like characters only (kebab-case, ASCII).
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $studio->getSlug());
    }

    /**
     * toArray() expose name, slug, description, logoUrl (null ici),
     * bunnyFolder, isActive (true par défaut), ownerId (UUID du propriétaire)
     * ainsi que id, createdAt et updatedAt.
     */
    public function testToArrayShape(): void
    {
        $owner = $this->makeOwner();
        $studio = new Studio();
        $studio
            ->setName('Ouaga Films')
            ->setSlug('ouaga-films')
            ->setOwner($owner)
            ->setDescription('Studio de production basé en Afrique')
            ->setBunnyFolder('studios/ouaga-films/');

        $array = $studio->toArray();

        $this->assertSame('Ouaga Films', $array['name']);
        $this->assertSame('ouaga-films', $array['slug']);
        $this->assertSame('Studio de production basé en Afrique', $array['description']);
        $this->assertNull($array['logoUrl']);
        $this->assertSame('studios/ouaga-films/', $array['bunnyFolder']);
        $this->assertTrue($array['isActive']);
        $this->assertSame($owner->getId()->toRfc4122(), $array['ownerId']);
        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('createdAt', $array);
        $this->assertArrayHasKey('updatedAt', $array);
    }

    /** Propriétaire factice (ROLE_CREATEUR), non persisté. */
    private function makeOwner(): User
    {
        $u = new User();
        $u->setEmail('owner@example.com');
        $u->setFirstName('Producer');
        $u->setLastName('Test');
        $u->setPassword('hashed');
        $u->setRoles(['ROLE_CREATEUR']);
        return $u;
    }
}
