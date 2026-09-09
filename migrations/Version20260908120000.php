<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create users, appointment booking settings, admin availabilities and appointments.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SQLitePlatform, 'Cette migration initiale est prévue pour la base SQLite locale du projet.');

        $this->addSql('CREATE TABLE app_user (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles CLOB NOT NULL, password VARCHAR(255) NOT NULL, must_change_password BOOLEAN NOT NULL, temporary_password_issued_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL ON app_user (email)');
        $this->addSql('CREATE TABLE appointment_setting (id INTEGER NOT NULL, no_show_delay_minutes INTEGER NOT NULL, booking_window_days INTEGER NOT NULL, default_slot_duration_minutes INTEGER NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE appointment_availability (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, day_of_week INTEGER NOT NULL, start_time TIME NOT NULL, end_time TIME NOT NULL, slot_duration_minutes INTEGER NOT NULL, is_enabled BOOLEAN NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX IDX_APPOINTMENT_AVAILABILITY_DAY ON appointment_availability (day_of_week, is_enabled)');
        $this->addSql('CREATE TABLE appointment (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, phone VARCHAR(40) DEFAULT NULL, device VARCHAR(80) NOT NULL, problem VARCHAR(80) NOT NULL, scheduled_at DATETIME NOT NULL, duration_minutes INTEGER NOT NULL, status VARCHAR(40) NOT NULL, customer_note CLOB DEFAULT NULL, admin_note CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, status_changed_at DATETIME DEFAULT NULL, cancelled_at DATETIME DEFAULT NULL, rescheduled_at DATETIME DEFAULT NULL, customer_id INTEGER NOT NULL, CONSTRAINT FK_APPOINTMENT_CUSTOMER FOREIGN KEY (customer_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_APPOINTMENT_STATUS_SCHEDULED_AT ON appointment (status, scheduled_at)');
        $this->addSql('CREATE INDEX IDX_APPOINTMENT_EMAIL ON appointment (email)');
        $this->addSql('CREATE INDEX IDX_APPOINTMENT_CUSTOMER ON appointment (customer_id)');

        $this->seedData();
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE appointment');
        $this->addSql('DROP TABLE appointment_availability');
        $this->addSql('DROP TABLE appointment_setting');
        $this->addSql('DROP TABLE app_user');
    }

    private function seedData(): void
    {
        $isPostgreSql = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        $true = $isPostgreSql ? 'true' : '1';
        $false = $isPostgreSql ? 'false' : '0';

        $this->addSql(
            sprintf('INSERT INTO app_user (email, roles, password, must_change_password, temporary_password_issued_at, created_at, updated_at) VALUES (:admin_email, :admin_roles, :admin_password, %s, NULL, CURRENT_TIMESTAMP, NULL)', $false),
            [
                'admin_email' => 'admin@symaclinic.fr',
                'admin_roles' => '["ROLE_ADMIN"]',
                'admin_password' => '$2y$13$5kHhmBGnTItTmhBsZlHk3u0NgwY/mXoyLotl5r3flZDNRm4ZpIjSe',
            ],
        );
        $this->addSql(
            sprintf('INSERT INTO app_user (email, roles, password, must_change_password, temporary_password_issued_at, created_at, updated_at) VALUES (:client_email, :client_roles, :client_password, %s, NULL, CURRENT_TIMESTAMP, NULL)', $false),
            [
                'client_email' => 'client@symaclinic.fr',
                'client_roles' => '["ROLE_USER"]',
                'client_password' => '$2y$13$gJSYYlZUlv39O24Xx3woKeybsuMEr9GqdaQHVfFvbpqOJf2q0orMm',
            ],
        );

        $this->addSql('INSERT INTO appointment_setting (id, no_show_delay_minutes, booking_window_days, default_slot_duration_minutes, created_at, updated_at) VALUES (1, 60, 21, 30, CURRENT_TIMESTAMP, NULL)');

        foreach ([1, 2, 3, 4, 5] as $dayOfWeek) {
            $this->addSql(sprintf(
                "INSERT INTO appointment_availability (day_of_week, start_time, end_time, slot_duration_minutes, is_enabled, created_at, updated_at) VALUES (%d, '09:00:00', '12:00:00', 30, %s, CURRENT_TIMESTAMP, NULL)",
                $dayOfWeek,
                $true,
            ));
            $this->addSql(sprintf(
                "INSERT INTO appointment_availability (day_of_week, start_time, end_time, slot_duration_minutes, is_enabled, created_at, updated_at) VALUES (%d, '14:00:00', '18:00:00', 30, %s, CURRENT_TIMESTAMP, NULL)",
                $dayOfWeek,
                $true,
            ));
        }

        $this->addSql(sprintf(
            "INSERT INTO appointment_availability (day_of_week, start_time, end_time, slot_duration_minutes, is_enabled, created_at, updated_at) VALUES (6, '10:00:00', '13:00:00', 30, %s, CURRENT_TIMESTAMP, NULL)",
            $true,
        ));
    }
}
