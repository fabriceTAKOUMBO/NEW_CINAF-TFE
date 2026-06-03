<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260423012959 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sprint 2 — Catalogue (Film, Serie, Season, Episode, Genre, Country, Language, Person, Tag, FeaturedContent)';
    }

    public function up(Schema $schema): void
    {
        // Le bundle Gesdinet utilise un mapped-superclass XML que Doctrine ne détecte pas,
        // donc diff veut drop refresh_tokens — on ne touche PAS à cette table (gotcha Sprint 1).
        $this->addSql('CREATE TABLE country (id UUID NOT NULL, name VARCHAR(100) NOT NULL, iso_code VARCHAR(3) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5373C96662B6A45E ON country (iso_code)');
        $this->addSql('CREATE TABLE episode (id UUID NOT NULL, number INT NOT NULL, title VARCHAR(255) NOT NULL, synopsis TEXT DEFAULT NULL, duration INT NOT NULL, bunny_video_id VARCHAR(100) DEFAULT NULL, is_premium BOOLEAN NOT NULL, season_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_DDAA1CDA4EC001D1 ON episode (season_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_episode_season_number ON episode (season_id, number)');
        $this->addSql('CREATE TABLE featured_content (id UUID NOT NULL, position INT NOT NULL, start_date TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, end_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, active BOOLEAN NOT NULL, film_id UUID DEFAULT NULL, serie_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_5F0420E4567F5183 ON featured_content (film_id)');
        $this->addSql('CREATE INDEX IDX_5F0420E4D94388BD ON featured_content (serie_id)');
        $this->addSql('CREATE TABLE film (id UUID NOT NULL, title VARCHAR(255) NOT NULL, slug VARCHAR(280) NOT NULL, synopsis TEXT NOT NULL, year INT NOT NULL, duration INT NOT NULL, poster VARCHAR(500) DEFAULT NULL, trailer_video_id VARCHAR(100) DEFAULT NULL, bunny_video_id VARCHAR(100) DEFAULT NULL, is_premium BOOLEAN NOT NULL, views INT NOT NULL, avg_rating DOUBLE PRECISION NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8244BE22989D9B62 ON film (slug)');
        $this->addSql('CREATE TABLE film_genre (film_id UUID NOT NULL, genre_id UUID NOT NULL, PRIMARY KEY (film_id, genre_id))');
        $this->addSql('CREATE INDEX IDX_1A3CCDA8567F5183 ON film_genre (film_id)');
        $this->addSql('CREATE INDEX IDX_1A3CCDA84296D31F ON film_genre (genre_id)');
        $this->addSql('CREATE TABLE film_country (film_id UUID NOT NULL, country_id UUID NOT NULL, PRIMARY KEY (film_id, country_id))');
        $this->addSql('CREATE INDEX IDX_B3CDD245567F5183 ON film_country (film_id)');
        $this->addSql('CREATE INDEX IDX_B3CDD245F92F3E70 ON film_country (country_id)');
        $this->addSql('CREATE TABLE film_director (film_id UUID NOT NULL, person_id UUID NOT NULL, PRIMARY KEY (film_id, person_id))');
        $this->addSql('CREATE INDEX IDX_BC171C99567F5183 ON film_director (film_id)');
        $this->addSql('CREATE INDEX IDX_BC171C99217BBB47 ON film_director (person_id)');
        $this->addSql('CREATE TABLE film_cast (film_id UUID NOT NULL, person_id UUID NOT NULL, PRIMARY KEY (film_id, person_id))');
        $this->addSql('CREATE INDEX IDX_771753F5567F5183 ON film_cast (film_id)');
        $this->addSql('CREATE INDEX IDX_771753F5217BBB47 ON film_cast (person_id)');
        $this->addSql('CREATE TABLE genre (id UUID NOT NULL, name VARCHAR(100) NOT NULL, slug VARCHAR(120) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_835033F85E237E06 ON genre (name)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_835033F8989D9B62 ON genre (slug)');
        $this->addSql('CREATE TABLE language (id UUID NOT NULL, name VARCHAR(100) NOT NULL, iso_code VARCHAR(5) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D4DB71B562B6A45E ON language (iso_code)');
        $this->addSql('CREATE TABLE person (id UUID NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, photo VARCHAR(500) DEFAULT NULL, biography TEXT DEFAULT NULL, birth_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE season (id UUID NOT NULL, number INT NOT NULL, title VARCHAR(255) DEFAULT NULL, synopsis TEXT DEFAULT NULL, serie_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F0E45BA9D94388BD ON season (serie_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_season_serie_number ON season (serie_id, number)');
        $this->addSql('CREATE TABLE serie (id UUID NOT NULL, title VARCHAR(255) NOT NULL, slug VARCHAR(280) NOT NULL, synopsis TEXT NOT NULL, year INT NOT NULL, poster VARCHAR(500) DEFAULT NULL, trailer_video_id VARCHAR(100) DEFAULT NULL, is_premium BOOLEAN NOT NULL, nb_seasons INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AA3A9334989D9B62 ON serie (slug)');
        $this->addSql('CREATE TABLE serie_genre (serie_id UUID NOT NULL, genre_id UUID NOT NULL, PRIMARY KEY (serie_id, genre_id))');
        $this->addSql('CREATE INDEX IDX_4B5C076CD94388BD ON serie_genre (serie_id)');
        $this->addSql('CREATE INDEX IDX_4B5C076C4296D31F ON serie_genre (genre_id)');
        $this->addSql('CREATE TABLE serie_country (serie_id UUID NOT NULL, country_id UUID NOT NULL, PRIMARY KEY (serie_id, country_id))');
        $this->addSql('CREATE INDEX IDX_67EABAC1D94388BD ON serie_country (serie_id)');
        $this->addSql('CREATE INDEX IDX_67EABAC1F92F3E70 ON serie_country (country_id)');
        $this->addSql('CREATE TABLE tag (id UUID NOT NULL, name VARCHAR(100) NOT NULL, slug VARCHAR(120) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_389B7835E237E06 ON tag (name)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_389B783989D9B62 ON tag (slug)');
        $this->addSql('CREATE TABLE film_tag (tag_id UUID NOT NULL, film_id UUID NOT NULL, PRIMARY KEY (tag_id, film_id))');
        $this->addSql('CREATE INDEX IDX_1CBA72DBBAD26311 ON film_tag (tag_id)');
        $this->addSql('CREATE INDEX IDX_1CBA72DB567F5183 ON film_tag (film_id)');
        $this->addSql('CREATE TABLE serie_tag (tag_id UUID NOT NULL, serie_id UUID NOT NULL, PRIMARY KEY (tag_id, serie_id))');
        $this->addSql('CREATE INDEX IDX_DD5453A9BAD26311 ON serie_tag (tag_id)');
        $this->addSql('CREATE INDEX IDX_DD5453A9D94388BD ON serie_tag (serie_id)');
        $this->addSql('ALTER TABLE episode ADD CONSTRAINT FK_DDAA1CDA4EC001D1 FOREIGN KEY (season_id) REFERENCES season (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE featured_content ADD CONSTRAINT FK_5F0420E4567F5183 FOREIGN KEY (film_id) REFERENCES film (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE featured_content ADD CONSTRAINT FK_5F0420E4D94388BD FOREIGN KEY (serie_id) REFERENCES serie (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE film_genre ADD CONSTRAINT FK_1A3CCDA8567F5183 FOREIGN KEY (film_id) REFERENCES film (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE film_genre ADD CONSTRAINT FK_1A3CCDA84296D31F FOREIGN KEY (genre_id) REFERENCES genre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE film_country ADD CONSTRAINT FK_B3CDD245567F5183 FOREIGN KEY (film_id) REFERENCES film (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE film_country ADD CONSTRAINT FK_B3CDD245F92F3E70 FOREIGN KEY (country_id) REFERENCES country (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE film_director ADD CONSTRAINT FK_BC171C99567F5183 FOREIGN KEY (film_id) REFERENCES film (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE film_director ADD CONSTRAINT FK_BC171C99217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE film_cast ADD CONSTRAINT FK_771753F5567F5183 FOREIGN KEY (film_id) REFERENCES film (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE film_cast ADD CONSTRAINT FK_771753F5217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE season ADD CONSTRAINT FK_F0E45BA9D94388BD FOREIGN KEY (serie_id) REFERENCES serie (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE serie_genre ADD CONSTRAINT FK_4B5C076CD94388BD FOREIGN KEY (serie_id) REFERENCES serie (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE serie_genre ADD CONSTRAINT FK_4B5C076C4296D31F FOREIGN KEY (genre_id) REFERENCES genre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE serie_country ADD CONSTRAINT FK_67EABAC1D94388BD FOREIGN KEY (serie_id) REFERENCES serie (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE serie_country ADD CONSTRAINT FK_67EABAC1F92F3E70 FOREIGN KEY (country_id) REFERENCES country (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE film_tag ADD CONSTRAINT FK_1CBA72DBBAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE film_tag ADD CONSTRAINT FK_1CBA72DB567F5183 FOREIGN KEY (film_id) REFERENCES film (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE serie_tag ADD CONSTRAINT FK_DD5453A9BAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE serie_tag ADD CONSTRAINT FK_DD5453A9D94388BD FOREIGN KEY (serie_id) REFERENCES serie (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE episode DROP CONSTRAINT FK_DDAA1CDA4EC001D1');
        $this->addSql('ALTER TABLE featured_content DROP CONSTRAINT FK_5F0420E4567F5183');
        $this->addSql('ALTER TABLE featured_content DROP CONSTRAINT FK_5F0420E4D94388BD');
        $this->addSql('ALTER TABLE film_genre DROP CONSTRAINT FK_1A3CCDA8567F5183');
        $this->addSql('ALTER TABLE film_genre DROP CONSTRAINT FK_1A3CCDA84296D31F');
        $this->addSql('ALTER TABLE film_country DROP CONSTRAINT FK_B3CDD245567F5183');
        $this->addSql('ALTER TABLE film_country DROP CONSTRAINT FK_B3CDD245F92F3E70');
        $this->addSql('ALTER TABLE film_director DROP CONSTRAINT FK_BC171C99567F5183');
        $this->addSql('ALTER TABLE film_director DROP CONSTRAINT FK_BC171C99217BBB47');
        $this->addSql('ALTER TABLE film_cast DROP CONSTRAINT FK_771753F5567F5183');
        $this->addSql('ALTER TABLE film_cast DROP CONSTRAINT FK_771753F5217BBB47');
        $this->addSql('ALTER TABLE season DROP CONSTRAINT FK_F0E45BA9D94388BD');
        $this->addSql('ALTER TABLE serie_genre DROP CONSTRAINT FK_4B5C076CD94388BD');
        $this->addSql('ALTER TABLE serie_genre DROP CONSTRAINT FK_4B5C076C4296D31F');
        $this->addSql('ALTER TABLE serie_country DROP CONSTRAINT FK_67EABAC1D94388BD');
        $this->addSql('ALTER TABLE serie_country DROP CONSTRAINT FK_67EABAC1F92F3E70');
        $this->addSql('ALTER TABLE film_tag DROP CONSTRAINT FK_1CBA72DBBAD26311');
        $this->addSql('ALTER TABLE film_tag DROP CONSTRAINT FK_1CBA72DB567F5183');
        $this->addSql('ALTER TABLE serie_tag DROP CONSTRAINT FK_DD5453A9BAD26311');
        $this->addSql('ALTER TABLE serie_tag DROP CONSTRAINT FK_DD5453A9D94388BD');
        $this->addSql('DROP TABLE country');
        $this->addSql('DROP TABLE episode');
        $this->addSql('DROP TABLE featured_content');
        $this->addSql('DROP TABLE film');
        $this->addSql('DROP TABLE film_genre');
        $this->addSql('DROP TABLE film_country');
        $this->addSql('DROP TABLE film_director');
        $this->addSql('DROP TABLE film_cast');
        $this->addSql('DROP TABLE genre');
        $this->addSql('DROP TABLE language');
        $this->addSql('DROP TABLE person');
        $this->addSql('DROP TABLE season');
        $this->addSql('DROP TABLE serie');
        $this->addSql('DROP TABLE serie_genre');
        $this->addSql('DROP TABLE serie_country');
        $this->addSql('DROP TABLE tag');
        $this->addSql('DROP TABLE film_tag');
        $this->addSql('DROP TABLE serie_tag');
    }
}
