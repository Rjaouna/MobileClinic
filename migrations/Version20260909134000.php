<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909134000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align the general settings identifier with generated setting entities.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('general_setting') || !$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            return;
        }

        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql('CREATE TEMPORARY TABLE __temp__general_setting AS SELECT id, store_phone, product_reservation_hold_minutes, created_at, updated_at FROM general_setting');
        $this->addSql('DROP TABLE general_setting');
        $this->addSql('CREATE TABLE general_setting (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, store_phone VARCHAR(40) NOT NULL, product_reservation_hold_minutes INTEGER NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL)');
        $this->addSql('INSERT INTO general_setting (id, store_phone, product_reservation_hold_minutes, created_at, updated_at) SELECT id, store_phone, product_reservation_hold_minutes, created_at, updated_at FROM __temp__general_setting');
        $this->addSql('DROP TABLE __temp__general_setting');
        $this->addSql('PRAGMA foreign_keys = ON');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('general_setting') || !$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            return;
        }

        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql('CREATE TEMPORARY TABLE __temp__general_setting AS SELECT id, store_phone, product_reservation_hold_minutes, created_at, updated_at FROM general_setting');
        $this->addSql('DROP TABLE general_setting');
        $this->addSql('CREATE TABLE general_setting (id INTEGER NOT NULL, store_phone VARCHAR(40) NOT NULL, product_reservation_hold_minutes INTEGER NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('INSERT INTO general_setting (id, store_phone, product_reservation_hold_minutes, created_at, updated_at) SELECT id, store_phone, product_reservation_hold_minutes, created_at, updated_at FROM __temp__general_setting');
        $this->addSql('DROP TABLE __temp__general_setting');
        $this->addSql('PRAGMA foreign_keys = ON');
    }
}
