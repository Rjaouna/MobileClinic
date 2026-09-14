<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909133000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reconcile SQLite constraints for shop reservation loyalty transactions.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('loyalty_transaction') || !$schema->hasTable('product_reservation')) {
            return;
        }

        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->addSql('PRAGMA foreign_keys = OFF');
            $this->rebuildLoyaltyTransactionWithProductReservationForeignKey();

            if ($schema->hasTable('general_setting')) {
                $this->rebuildGeneralSetting();
            }

            $this->addSql('PRAGMA foreign_keys = ON');

            return;
        }

        $loyaltyTransaction = $schema->getTable('loyalty_transaction');

        if (!$loyaltyTransaction->hasIndex('IDX_LOYALTY_TRANSACTION_ADMINISTRATOR')) {
            $this->addSql('CREATE INDEX IDX_LOYALTY_TRANSACTION_ADMINISTRATOR ON loyalty_transaction (administrator_id)');
        }

        if (!$loyaltyTransaction->hasForeignKey('FK_4CE4AEC1EB5BE9CB')) {
            $this->addSql('ALTER TABLE loyalty_transaction ADD CONSTRAINT FK_4CE4AEC1EB5BE9CB FOREIGN KEY (product_reservation_id) REFERENCES product_reservation (id) ON DELETE SET NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('loyalty_transaction')) {
            return;
        }

        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->addSql('PRAGMA foreign_keys = OFF');
            $this->addSql('CREATE TEMPORARY TABLE __temp__loyalty_transaction AS SELECT id, account_id, appointment_id, administrator_id, amount_cents, type, status, reason, created_at, validated_at, cancelled_at, product_reservation_id FROM loyalty_transaction');
            $this->addSql('DROP TABLE loyalty_transaction');
            $this->addSql('CREATE TABLE loyalty_transaction (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, account_id INTEGER NOT NULL, appointment_id INTEGER DEFAULT NULL, administrator_id INTEGER DEFAULT NULL, amount_cents INTEGER NOT NULL, type VARCHAR(40) NOT NULL, status VARCHAR(40) NOT NULL, reason CLOB NOT NULL, created_at DATETIME NOT NULL, validated_at DATETIME DEFAULT NULL, cancelled_at DATETIME DEFAULT NULL, product_reservation_id INTEGER DEFAULT NULL, CONSTRAINT FK_LOYALTY_TRANSACTION_ACCOUNT FOREIGN KEY (account_id) REFERENCES loyalty_account (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_LOYALTY_TRANSACTION_APPOINTMENT FOREIGN KEY (appointment_id) REFERENCES appointment (id) ON UPDATE NO ACTION ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_LOYALTY_TRANSACTION_ADMINISTRATOR FOREIGN KEY (administrator_id) REFERENCES app_user (id) ON UPDATE NO ACTION ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('INSERT INTO loyalty_transaction (id, account_id, appointment_id, administrator_id, amount_cents, type, status, reason, created_at, validated_at, cancelled_at, product_reservation_id) SELECT id, account_id, appointment_id, administrator_id, amount_cents, type, status, reason, created_at, validated_at, cancelled_at, product_reservation_id FROM __temp__loyalty_transaction');
            $this->addSql('DROP TABLE __temp__loyalty_transaction');
            $this->addSql('CREATE INDEX IDX_LOYALTY_TRANSACTION_PRODUCT_RESERVATION ON loyalty_transaction (product_reservation_id)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_LOYALTY_TRANSACTION_APPOINTMENT ON loyalty_transaction (appointment_id)');
            $this->addSql('CREATE INDEX IDX_LOYALTY_TRANSACTION_STATUS ON loyalty_transaction (status)');
            $this->addSql('CREATE INDEX IDX_LOYALTY_TRANSACTION_TYPE ON loyalty_transaction (type)');
            $this->addSql('CREATE INDEX IDX_LOYALTY_TRANSACTION_ACCOUNT ON loyalty_transaction (account_id)');
            $this->addSql('CREATE INDEX IDX_LOYALTY_TRANSACTION_ADMINISTRATOR ON loyalty_transaction (administrator_id)');
            $this->addSql('PRAGMA foreign_keys = ON');

            return;
        }

        if ($schema->getTable('loyalty_transaction')->hasForeignKey('FK_4CE4AEC1EB5BE9CB')) {
            $this->addSql('ALTER TABLE loyalty_transaction DROP FOREIGN KEY FK_4CE4AEC1EB5BE9CB');
        }
    }

    private function rebuildLoyaltyTransactionWithProductReservationForeignKey(): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__loyalty_transaction AS SELECT id, account_id, appointment_id, administrator_id, amount_cents, type, status, reason, created_at, validated_at, cancelled_at, product_reservation_id FROM loyalty_transaction');
        $this->addSql('DROP TABLE loyalty_transaction');
        $this->addSql('CREATE TABLE loyalty_transaction (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, account_id INTEGER NOT NULL, appointment_id INTEGER DEFAULT NULL, administrator_id INTEGER DEFAULT NULL, amount_cents INTEGER NOT NULL, type VARCHAR(40) NOT NULL, status VARCHAR(40) NOT NULL, reason CLOB NOT NULL, created_at DATETIME NOT NULL, validated_at DATETIME DEFAULT NULL, cancelled_at DATETIME DEFAULT NULL, product_reservation_id INTEGER DEFAULT NULL, CONSTRAINT FK_LOYALTY_TRANSACTION_ACCOUNT FOREIGN KEY (account_id) REFERENCES loyalty_account (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_LOYALTY_TRANSACTION_APPOINTMENT FOREIGN KEY (appointment_id) REFERENCES appointment (id) ON UPDATE NO ACTION ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_LOYALTY_TRANSACTION_ADMINISTRATOR FOREIGN KEY (administrator_id) REFERENCES app_user (id) ON UPDATE NO ACTION ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_4CE4AEC1EB5BE9CB FOREIGN KEY (product_reservation_id) REFERENCES product_reservation (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO loyalty_transaction (id, account_id, appointment_id, administrator_id, amount_cents, type, status, reason, created_at, validated_at, cancelled_at, product_reservation_id) SELECT id, account_id, appointment_id, administrator_id, amount_cents, type, status, reason, created_at, validated_at, cancelled_at, product_reservation_id FROM __temp__loyalty_transaction');
        $this->addSql('DROP TABLE __temp__loyalty_transaction');
        $this->addSql('CREATE INDEX IDX_LOYALTY_TRANSACTION_PRODUCT_RESERVATION ON loyalty_transaction (product_reservation_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_LOYALTY_TRANSACTION_APPOINTMENT ON loyalty_transaction (appointment_id)');
        $this->addSql('CREATE INDEX IDX_LOYALTY_TRANSACTION_STATUS ON loyalty_transaction (status)');
        $this->addSql('CREATE INDEX IDX_LOYALTY_TRANSACTION_TYPE ON loyalty_transaction (type)');
        $this->addSql('CREATE INDEX IDX_LOYALTY_TRANSACTION_ACCOUNT ON loyalty_transaction (account_id)');
        $this->addSql('CREATE INDEX IDX_LOYALTY_TRANSACTION_ADMINISTRATOR ON loyalty_transaction (administrator_id)');
    }

    private function rebuildGeneralSetting(): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__general_setting AS SELECT id, store_phone, product_reservation_hold_minutes, created_at, updated_at FROM general_setting');
        $this->addSql('DROP TABLE general_setting');
        $this->addSql('CREATE TABLE general_setting (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, store_phone VARCHAR(40) NOT NULL, product_reservation_hold_minutes INTEGER NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL)');
        $this->addSql('INSERT INTO general_setting (id, store_phone, product_reservation_hold_minutes, created_at, updated_at) SELECT id, store_phone, product_reservation_hold_minutes, created_at, updated_at FROM __temp__general_setting');
        $this->addSql('DROP TABLE __temp__general_setting');
    }
}
