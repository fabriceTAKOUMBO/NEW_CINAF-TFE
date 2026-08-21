<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Dissociation films / séries du catalogue importé depuis Bunny.
 *
 * Schéma :
 *  - Table `film_part` : parties vidéo d'un film. Le catalogue historique
 *    livre certains films en plusieurs morceaux (`FILMS/GUCCI_BROTHERS/PART1…`)
 *    que `Film.bunny_video_id`, unique, ne pouvait pas porter.
 *      · PK : id UUID
 *      · FK : film_id (ON DELETE CASCADE)
 *      · Unique : (film_id, number) → un rang de partie par film
 *  - Colonne `film.bunny_folder` / `serie.bunny_folder` : dossier Bunny racine
 *    dont l'œuvre est issue. Sert de clé d'idempotence à
 *    `app:catalogue:import-bunny` et de critère à son option `--purge`.
 *    Reste NULL pour le contenu créé via le module Studio, qui n'est donc
 *    jamais purgé. Remplace le détournement de `serie.trailer_video_id`, qui
 *    stockait ce chemin et peut désormais porter la vraie bande-annonce.
 *
 * Migration écrite à la main : `doctrine:migrations:diff` échoue sur ce poste
 * (E/S OneDrive, errno=22). Les statements parasites habituels
 * (`DROP TABLE refresh_tokens`, cf. CLAUDE.md gotcha #1) sont de fait absents.
 */
final class Version20260821090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute film_part + film.bunny_folder et serie.bunny_folder (dissociation films/séries).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE film_part (id UUID NOT NULL, film_id UUID NOT NULL, number INT NOT NULL, title VARCHAR(255) NOT NULL, bunny_video_id VARCHAR(500) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2CA09AC5567F5183 ON film_part (film_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_film_part_number ON film_part (film_id, number)');
        $this->addSql('ALTER TABLE film_part ADD CONSTRAINT FK_2CA09AC5567F5183 FOREIGN KEY (film_id) REFERENCES film (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('ALTER TABLE film ADD bunny_folder VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE serie ADD bunny_folder VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE serie DROP bunny_folder');
        $this->addSql('ALTER TABLE film DROP bunny_folder');

        $this->addSql('ALTER TABLE film_part DROP CONSTRAINT FK_2CA09AC5567F5183');
        $this->addSql('DROP TABLE film_part');
    }
}
