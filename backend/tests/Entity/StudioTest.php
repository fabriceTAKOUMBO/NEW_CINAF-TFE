<?php

namespace App\Tests\Entity;

use App\Entity\Studio;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Pure unit tests for the Studio entity (no DB, no kernel boot).
 */
class StudioTest extends TestCase
{
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

    public function testSlugSetterAcceptsExpectedFormat(): void
    {
        $studio = new Studio();
        $studio->setSlug('cinema-du-sahel');

        $this->assertSame('cinema-du-sahel', $studio->getSlug());
        // Sanity: slug-like characters only (kebab-case, ASCII).
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $studio->getSlug());
    }

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
