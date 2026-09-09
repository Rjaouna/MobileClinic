<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align the local SQLite schema with Doctrine mapping and Messenger storage.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SQLitePlatform, 'Cette migration corrective est prévue pour SQLite.');

        $this->addSql('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, body CLOB NOT NULL, headers CLOB NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__appointment AS SELECT id, email, phone, device, problem, scheduled_at, duration_minutes, status, customer_note, admin_note, created_at, updated_at, status_changed_at, cancelled_at, rescheduled_at, customer_id FROM appointment');
        $this->addSql('DROP TABLE appointment');
        $this->addSql('CREATE TABLE appointment (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, phone VARCHAR(40) DEFAULT NULL, device VARCHAR(80) NOT NULL, problem VARCHAR(80) NOT NULL, scheduled_at DATETIME NOT NULL, duration_minutes INTEGER NOT NULL, status VARCHAR(40) NOT NULL, customer_note CLOB DEFAULT NULL, admin_note CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, status_changed_at DATETIME DEFAULT NULL, cancelled_at DATETIME DEFAULT NULL, rescheduled_at DATETIME DEFAULT NULL, customer_id INTEGER NOT NULL, CONSTRAINT FK_APPOINTMENT_CUSTOMER FOREIGN KEY (customer_id) REFERENCES app_user (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO appointment (id, email, phone, device, problem, scheduled_at, duration_minutes, status, customer_note, admin_note, created_at, updated_at, status_changed_at, cancelled_at, rescheduled_at, customer_id) SELECT id, email, phone, device, problem, scheduled_at, duration_minutes, status, customer_note, admin_note, created_at, updated_at, status_changed_at, cancelled_at, rescheduled_at, customer_id FROM __temp__appointment');
        $this->addSql('DROP TABLE __temp__appointment');
        $this->addSql('CREATE INDEX IDX_APPOINTMENT_EMAIL ON appointment (email)');
        $this->addSql('CREATE INDEX IDX_APPOINTMENT_STATUS_SCHEDULED_AT ON appointment (status, scheduled_at)');
        $this->addSql('CREATE INDEX IDX_FE38F8449395C3F3 ON appointment (customer_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__appointment_setting AS SELECT id, no_show_delay_minutes, booking_window_days, default_slot_duration_minutes, created_at, updated_at FROM appointment_setting');
        $this->addSql('DROP TABLE appointment_setting');
        $this->addSql('CREATE TABLE appointment_setting (id INTEGER NOT NULL, no_show_delay_minutes INTEGER NOT NULL, booking_window_days INTEGER NOT NULL, default_slot_duration_minutes INTEGER NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('INSERT INTO appointment_setting (id, no_show_delay_minutes, booking_window_days, default_slot_duration_minutes, created_at, updated_at) SELECT id, no_show_delay_minutes, booking_window_days, default_slot_duration_minutes, created_at, updated_at FROM __temp__appointment_setting');
        $this->addSql('DROP TABLE __temp__appointment_setting');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('DROP INDEX IDX_FE38F8449395C3F3');
        $this->addSql('CREATE INDEX IDX_APPOINTMENT_CUSTOMER ON appointment (customer_id)');
    }
}
