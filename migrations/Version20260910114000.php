<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910114000 extends AbstractMigration
{
    private const DEFAULT_HOME_HERO_PHOTO_PATH = 'https://unsplash.com/photos/person-repairing-smartphones-under-a-lighted-table-PZLgTUAhxMM/download?force=true';

    public function getDescription(): string
    {
        return 'Normalize general settings schema after adding the home hero photo.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('general_setting') || !$schema->getTable('general_setting')->hasColumn('home_hero_photo_path')) {
            return;
        }

        $this->addSql('CREATE TEMPORARY TABLE __temp__general_setting AS SELECT id, store_phone, product_reservation_hold_minutes, created_at, updated_at, home_hero_photo_path FROM general_setting');
        $this->addSql('DROP TABLE general_setting');
        $this->addSql('CREATE TABLE general_setting (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, store_phone VARCHAR(40) NOT NULL, product_reservation_hold_minutes INTEGER NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, home_hero_photo_path VARCHAR(500) NOT NULL)');
        $this->addSql('INSERT INTO general_setting (id, store_phone, product_reservation_hold_minutes, created_at, updated_at, home_hero_photo_path) SELECT id, store_phone, product_reservation_hold_minutes, created_at, updated_at, home_hero_photo_path FROM __temp__general_setting');
        $this->addSql('DROP TABLE __temp__general_setting');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('general_setting') || !$schema->getTable('general_setting')->hasColumn('home_hero_photo_path')) {
            return;
        }

        $defaultPath = $this->connection->quote(self::DEFAULT_HOME_HERO_PHOTO_PATH);
        $this->addSql('CREATE TEMPORARY TABLE __temp__general_setting AS SELECT id, store_phone, product_reservation_hold_minutes, created_at, updated_at, home_hero_photo_path FROM general_setting');
        $this->addSql('DROP TABLE general_setting');
        $this->addSql(sprintf('CREATE TABLE general_setting (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, store_phone VARCHAR(40) NOT NULL, product_reservation_hold_minutes INTEGER NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, home_hero_photo_path VARCHAR(500) DEFAULT %s NOT NULL)', $defaultPath));
        $this->addSql('INSERT INTO general_setting (id, store_phone, product_reservation_hold_minutes, created_at, updated_at, home_hero_photo_path) SELECT id, store_phone, product_reservation_hold_minutes, created_at, updated_at, home_hero_photo_path FROM __temp__general_setting');
        $this->addSql('DROP TABLE __temp__general_setting');
    }
}
