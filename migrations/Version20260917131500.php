<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260917131500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align the SQLite customer check-in foreign keys and indexes with Doctrine mapping.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('customer_check_in')) {
            return;
        }

        $this->addSql('CREATE TEMPORARY TABLE __temp__customer_check_in AS SELECT id, customer_id, resolved_by_id, created_at, last_scanned_at, expires_at, scan_count, resolved_at, resolution FROM customer_check_in');
        $this->addSql('DROP TABLE customer_check_in');
        $this->addSql('CREATE TABLE customer_check_in (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, customer_id INTEGER NOT NULL, resolved_by_id INTEGER DEFAULT NULL, created_at DATETIME NOT NULL, last_scanned_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, scan_count INTEGER NOT NULL, resolved_at DATETIME DEFAULT NULL, resolution VARCHAR(30) DEFAULT NULL, CONSTRAINT FK_CUSTOMER_CHECK_IN_CUSTOMER FOREIGN KEY (customer_id) REFERENCES app_user (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_CUSTOMER_CHECK_IN_RESOLVED_BY FOREIGN KEY (resolved_by_id) REFERENCES app_user (id) ON UPDATE NO ACTION ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO customer_check_in (id, customer_id, resolved_by_id, created_at, last_scanned_at, expires_at, scan_count, resolved_at, resolution) SELECT id, customer_id, resolved_by_id, created_at, last_scanned_at, expires_at, scan_count, resolved_at, resolution FROM __temp__customer_check_in');
        $this->addSql('DROP TABLE __temp__customer_check_in');
        $this->addSql('CREATE INDEX IDX_CUSTOMER_CHECK_IN_LAST_SCAN ON customer_check_in (last_scanned_at)');
        $this->addSql('CREATE INDEX IDX_CUSTOMER_CHECK_IN_RESOLVED ON customer_check_in (resolved_at)');
        $this->addSql('CREATE INDEX IDX_CUSTOMER_CHECK_IN_CUSTOMER ON customer_check_in (customer_id)');
        $this->addSql('CREATE INDEX IDX_BC832FD66713A32B ON customer_check_in (resolved_by_id)');
    }

    public function down(Schema $schema): void
    {
        // The previous schema is functionally equivalent; no data downgrade is required.
    }
}
