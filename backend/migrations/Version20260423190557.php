<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sprint 7 — Dashboard admin : ajout du champ `is_suspended` sur la table `user`.
 *
 * Un compte suspendu par un administrateur est refusé au login (403). La valeur
 * par défaut `false` laisse actifs tous les comptes déjà existants.
 *
 * Note : la génération automatique produit un DROP sur `refresh_tokens` (table
 * du bundle gesdinet déclarée via mapped-superclass XML, non détectée par
 * Doctrine). Ces instructions ont été retirées manuellement (cf. gotcha #1
 * dans DOC/MEMORY/BACKEND_MEMORY.md).
 */
final class Version20260423190557 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sprint 7 — Ajout de la colonne is_suspended sur la table user (suspension admin).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD is_suspended BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP is_suspended');
    }
}
