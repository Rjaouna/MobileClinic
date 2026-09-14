<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910213100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalize the SQLite recruitment application relation and foreign key.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SQLitePlatform, 'This normalization is only required for SQLite.');

        if (!$schema->hasTable('recruitment_application') || !$schema->getTable('recruitment_application')->hasColumn('position_id')) {
            return;
        }

        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql('CREATE TEMPORARY TABLE __temp__recruitment_application AS SELECT id, first_name, last_name, email, phone, desired_position, availability, experience_level, message, resume_path, status, admin_note, created_at, updated_at, status_changed_at, position_id FROM recruitment_application');
        $this->addSql('DROP TABLE recruitment_application');
        $this->addSql('CREATE TABLE recruitment_application (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, first_name VARCHAR(80) NOT NULL, last_name VARCHAR(80) NOT NULL, email VARCHAR(180) NOT NULL, phone VARCHAR(40) NOT NULL, desired_position VARCHAR(120) NOT NULL, availability VARCHAR(80) NOT NULL, experience_level VARCHAR(80) NOT NULL, message CLOB NOT NULL, resume_path VARCHAR(255) DEFAULT NULL, status VARCHAR(40) NOT NULL, admin_note CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, status_changed_at DATETIME DEFAULT NULL, position_id INTEGER DEFAULT NULL, CONSTRAINT FK_RECRUITMENT_APPLICATION_POSITION FOREIGN KEY (position_id) REFERENCES recruitment_position (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO recruitment_application (id, first_name, last_name, email, phone, desired_position, availability, experience_level, message, resume_path, status, admin_note, created_at, updated_at, status_changed_at, position_id) SELECT id, first_name, last_name, email, phone, desired_position, availability, experience_level, message, resume_path, status, admin_note, created_at, updated_at, status_changed_at, position_id FROM __temp__recruitment_application');
        $this->addSql('DROP TABLE __temp__recruitment_application');
        $this->addSql('CREATE INDEX IDX_RECRUITMENT_EMAIL ON recruitment_application (email)');
        $this->addSql('CREATE INDEX IDX_RECRUITMENT_STATUS_CREATED ON recruitment_application (status, created_at)');
        $this->addSql('CREATE INDEX IDX_RECRUITMENT_APPLICATION_POSITION ON recruitment_application (position_id)');
        $this->addSql('PRAGMA foreign_keys = ON');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SQLitePlatform, 'This normalization is only required for SQLite.');

        if (!$schema->hasTable('recruitment_application') || !$schema->getTable('recruitment_application')->hasColumn('position_id')) {
            return;
        }

        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql('CREATE TEMPORARY TABLE __temp__recruitment_application AS SELECT id, first_name, last_name, email, phone, desired_position, availability, experience_level, message, resume_path, status, admin_note, created_at, updated_at, status_changed_at, position_id FROM recruitment_application');
        $this->addSql('DROP TABLE recruitment_application');
        $this->addSql('CREATE TABLE recruitment_application (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, first_name VARCHAR(80) NOT NULL, last_name VARCHAR(80) NOT NULL, email VARCHAR(180) NOT NULL, phone VARCHAR(40) NOT NULL, desired_position VARCHAR(120) NOT NULL, availability VARCHAR(80) NOT NULL, experience_level VARCHAR(80) NOT NULL, message CLOB NOT NULL, resume_path VARCHAR(255) DEFAULT NULL, status VARCHAR(40) NOT NULL, admin_note CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, status_changed_at DATETIME DEFAULT NULL, position_id INTEGER DEFAULT NULL)');
        $this->addSql('INSERT INTO recruitment_application (id, first_name, last_name, email, phone, desired_position, availability, experience_level, message, resume_path, status, admin_note, created_at, updated_at, status_changed_at, position_id) SELECT id, first_name, last_name, email, phone, desired_position, availability, experience_level, message, resume_path, status, admin_note, created_at, updated_at, status_changed_at, position_id FROM __temp__recruitment_application');
        $this->addSql('DROP TABLE __temp__recruitment_application');
        $this->addSql('CREATE INDEX IDX_RECRUITMENT_EMAIL ON recruitment_application (email)');
        $this->addSql('CREATE INDEX IDX_RECRUITMENT_STATUS_CREATED ON recruitment_application (status, created_at)');
        $this->addSql('CREATE INDEX IDX_RECRUITMENT_APPLICATION_POSITION ON recruitment_application (position_id)');
        $this->addSql('PRAGMA foreign_keys = ON');
    }
}
