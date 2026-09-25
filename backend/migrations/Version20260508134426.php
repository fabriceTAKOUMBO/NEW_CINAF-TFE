<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Élargit `bunny_video_id` (Film, Episode) et `trailer_video_id` (Film, Serie)
 * de VARCHAR(100) à VARCHAR(500).
 *
 * 3 conventions de paths Bunny coexistent dans la colonne et toutes peuvent
 * dépasser 100 caractères :
 *
 * 1. Import legacy (œuvres Phase F, ne commence PAS par `studios/`) :
 *    ex. `12_CAS/CAS_1/CAS1_E01/master.m3u8` — paths courts en général mais
 *    StudioOwnershipChecker::isImportedBunnyPath() s'appuie sur cette absence
 *    de préfixe pour interdire toute modification studio-side.
 *
 * 2. Ancien upload studio (avant 2026-05-08, payload `(file, kind)`) :
 *    `studios/{studio.slug}/{kind}s/{uuid}_{slug}.{ext}` — UUID + slug
 *    pousse régulièrement >100 chars, d'où cette migration.
 *
 * 3. Nouveau upload studio "1 dossier par projet" (depuis 2026-05-08,
 *    payload `(file, targetType, targetId, purpose)`) :
 *    - films/séries : `studios/{studio.slug}/{projet.slug}/{purpose}.{ext}`
 *    - épisodes    : `studios/{studio.slug}/{serie.slug}/saison-{N}/episode-{NN}/{purpose}.{ext}`
 *    Nommage déterministe (poster.jpg / trailer.mp4 / video.mp4), nouvel upload
 *    écrase l'ancien. Path construit par `App\Studio\Service\BunnyPathBuilder`.
 *    Les pattern (2) et (3) cohabitent sans migration de fichiers — la DB
 *    pointe simplement sur le bon path.
 *
 * NOTE : la diff Doctrine génère aussi des DROP de `refresh_tokens` (gotcha #1
 * mapped-superclass JWT, à conserver) et de `uniq_withdrawal_pending` (index
 * unique partiel SQL natif Phase A, non visible côté ORM). Ces lignes ont été
 * retirées manuellement, ainsi que le `studio_id DROP NOT NULL` (le NOT NULL
 * posé en Phase F par Version20260430200000 doit rester).
 */
final class Version20260508134426 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bunny video/trailer ids: VARCHAR(100) → VARCHAR(500) (paths studios/<slug>/<projet>/... + legacy import + ancien layout flat)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE episode ALTER bunny_video_id TYPE VARCHAR(500)');
        $this->addSql('ALTER TABLE film ALTER bunny_video_id TYPE VARCHAR(500)');
        $this->addSql('ALTER TABLE film ALTER trailer_video_id TYPE VARCHAR(500)');
        $this->addSql('ALTER TABLE serie ALTER trailer_video_id TYPE VARCHAR(500)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE episode ALTER bunny_video_id TYPE VARCHAR(100)');
        $this->addSql('ALTER TABLE film ALTER bunny_video_id TYPE VARCHAR(100)');
        $this->addSql('ALTER TABLE film ALTER trailer_video_id TYPE VARCHAR(100)');
        $this->addSql('ALTER TABLE serie ALTER trailer_video_id TYPE VARCHAR(100)');
    }
}
