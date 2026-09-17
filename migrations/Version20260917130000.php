<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260917130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the secure store QR check-in flow and persistent customer arrival notifications.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('general_setting')) {
            $table = $schema->getTable('general_setting');

            if (!$table->hasColumn('store_check_in_enabled')) {
                $this->addSql('ALTER TABLE general_setting ADD COLUMN store_check_in_enabled BOOLEAN DEFAULT 1 NOT NULL');
            }

            if (!$table->hasColumn('store_check_in_token')) {
                $this->addSql('ALTER TABLE general_setting ADD COLUMN store_check_in_token VARCHAR(64) DEFAULT NULL');
            }

            if (!$table->hasColumn('store_check_in_expiration_minutes')) {
                $this->addSql('ALTER TABLE general_setting ADD COLUMN store_check_in_expiration_minutes INTEGER DEFAULT 15 NOT NULL');
            }
        }

        if (!$schema->hasTable('customer_check_in')) {
            $this->addSql('CREATE TABLE customer_check_in (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, customer_id INTEGER NOT NULL, resolved_by_id INTEGER DEFAULT NULL, created_at DATETIME NOT NULL, last_scanned_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, scan_count INTEGER NOT NULL, resolved_at DATETIME DEFAULT NULL, resolution VARCHAR(30) DEFAULT NULL, CONSTRAINT FK_CUSTOMER_CHECK_IN_CUSTOMER FOREIGN KEY (customer_id) REFERENCES app_user (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_CUSTOMER_CHECK_IN_RESOLVED_BY FOREIGN KEY (resolved_by_id) REFERENCES app_user (id) ON UPDATE NO ACTION ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE INDEX IDX_CUSTOMER_CHECK_IN_CUSTOMER ON customer_check_in (customer_id)');
            $this->addSql('CREATE INDEX IDX_BC832FD66713A32B ON customer_check_in (resolved_by_id)');
            $this->addSql('CREATE INDEX IDX_CUSTOMER_CHECK_IN_RESOLVED ON customer_check_in (resolved_at)');
            $this->addSql('CREATE INDEX IDX_CUSTOMER_CHECK_IN_LAST_SCAN ON customer_check_in (last_scanned_at)');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('customer_check_in')) {
            $this->addSql('DROP TABLE customer_check_in');
        }

        if ($schema->hasTable('general_setting')) {
            $table = $schema->getTable('general_setting');

            if ($table->hasColumn('store_check_in_enabled')) {
                $this->addSql('ALTER TABLE general_setting DROP COLUMN store_check_in_enabled');
            }

            if ($table->hasColumn('store_check_in_token')) {
                $this->addSql('ALTER TABLE general_setting DROP COLUMN store_check_in_token');
            }

            if ($table->hasColumn('store_check_in_expiration_minutes')) {
                $this->addSql('ALTER TABLE general_setting DROP COLUMN store_check_in_expiration_minutes');
            }
        }
    }
}
