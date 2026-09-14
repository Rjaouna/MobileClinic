<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add appointment reminder notifications and configurable reminder delays.';
    }

    public function up(Schema $schema): void
    {
        $true = $this->booleanLiteral(true);

        if ($schema->hasTable('appointment_setting')) {
            $settingTable = $schema->getTable('appointment_setting');

            if (!$settingTable->hasColumn('reminders_enabled')) {
                $this->addSql(sprintf('ALTER TABLE appointment_setting ADD COLUMN reminders_enabled BOOLEAN DEFAULT %s NOT NULL', $true));
            }

            if (!$settingTable->hasColumn('first_reminder_delay_minutes')) {
                $this->addSql('ALTER TABLE appointment_setting ADD COLUMN first_reminder_delay_minutes INTEGER DEFAULT 1 NOT NULL');
            }

            if (!$settingTable->hasColumn('priority_reminder_delay_minutes')) {
                $this->addSql('ALTER TABLE appointment_setting ADD COLUMN priority_reminder_delay_minutes INTEGER DEFAULT 60 NOT NULL');
            }
        }

        if ($schema->hasTable('appointment')) {
            $appointmentTable = $schema->getTable('appointment');

            if (!$appointmentTable->hasColumn('status_changed_by_email')) {
                $this->addSql('ALTER TABLE appointment ADD COLUMN status_changed_by_email VARCHAR(180) DEFAULT NULL');
            }
        }

        if (!$schema->hasTable('appointment_notification')) {
            $notification = new Table('appointment_notification');
            $notification->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
            $notification->addColumn('unique_key', Types::STRING, ['length' => 120]);
            $notification->addColumn('type', Types::STRING, ['length' => 60]);
            $notification->addColumn('level', Types::STRING, ['length' => 30]);
            $notification->addColumn('appointment_id', Types::INTEGER, ['notnull' => false]);
            $notification->addColumn('recipient_email', Types::STRING, ['length' => 180, 'notnull' => false]);
            $notification->addColumn('title', Types::STRING, ['length' => 140]);
            $notification->addColumn('message', Types::TEXT);
            $notification->addColumn('status', Types::STRING, ['length' => 20]);
            $notification->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            $notification->addColumn('updated_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $notification->addColumn('read_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $notification->addColumn('resolved_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $notification->setPrimaryKey(['id']);
            $notification->addUniqueIndex(['unique_key'], 'UNIQ_APPOINTMENT_NOTIFICATION_KEY');
            $notification->addUniqueIndex(['type', 'appointment_id'], 'UNIQ_APPOINTMENT_NOTIFICATION_TYPE_APPOINTMENT');
            $notification->addIndex(['appointment_id'], 'IDX_APPOINTMENT_NOTIFICATION_APPOINTMENT');
            $notification->addIndex(['status'], 'IDX_APPOINTMENT_NOTIFICATION_STATUS');
            $notification->addIndex(['type', 'status'], 'IDX_APPOINTMENT_NOTIFICATION_TYPE_STATUS');
            $notification->addForeignKeyConstraint('appointment', ['appointment_id'], ['id'], ['onDelete' => 'CASCADE', 'onUpdate' => 'NO ACTION'], 'FK_APPOINTMENT_NOTIFICATION_APPOINTMENT');

            foreach ($this->connection->getDatabasePlatform()->getCreateTableSQL($notification) as $sql) {
                $this->addSql($sql);
            }
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('appointment_notification')) {
            $this->addSql('DROP TABLE appointment_notification');
        }

        if ($schema->hasTable('appointment') && $schema->getTable('appointment')->hasColumn('status_changed_by_email')) {
            $this->addSql('ALTER TABLE appointment DROP COLUMN status_changed_by_email');
        }

        if ($schema->hasTable('appointment_setting')) {
            $settingTable = $schema->getTable('appointment_setting');

            if ($settingTable->hasColumn('priority_reminder_delay_minutes')) {
                $this->addSql('ALTER TABLE appointment_setting DROP COLUMN priority_reminder_delay_minutes');
            }

            if ($settingTable->hasColumn('first_reminder_delay_minutes')) {
                $this->addSql('ALTER TABLE appointment_setting DROP COLUMN first_reminder_delay_minutes');
            }

            if ($settingTable->hasColumn('reminders_enabled')) {
                $this->addSql('ALTER TABLE appointment_setting DROP COLUMN reminders_enabled');
            }
        }
    }

    private function booleanLiteral(bool $value): string
    {
        $databaseValue = $this->connection->getDatabasePlatform()->convertBooleansToDatabaseValue($value);

        if (is_bool($databaseValue)) {
            return $databaseValue ? '1' : '0';
        }

        if (is_int($databaseValue) || is_float($databaseValue)) {
            return (string) $databaseValue;
        }

        return $this->connection->quote((string) $databaseValue);
    }
}
