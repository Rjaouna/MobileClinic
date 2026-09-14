<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add customer administration fields and loyalty account history.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $true = $this->booleanLiteral(true);

        if ($schema->hasTable('app_user')) {
            $userTable = $schema->getTable('app_user');

            if (!$userTable->hasColumn('first_name')) {
                $this->addSql('ALTER TABLE app_user ADD COLUMN first_name VARCHAR(100) DEFAULT NULL');
            }

            if (!$userTable->hasColumn('last_name')) {
                $this->addSql('ALTER TABLE app_user ADD COLUMN last_name VARCHAR(100) DEFAULT NULL');
            }

            if (!$userTable->hasColumn('is_active')) {
                $this->addSql(sprintf('ALTER TABLE app_user ADD COLUMN is_active BOOLEAN DEFAULT %s NOT NULL', $true));
            }
        }

        if (!$schema->hasTable('loyalty_setting')) {
            $loyaltySetting = new Table('loyalty_setting');
            $loyaltySetting->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
            $loyaltySetting->addColumn('appointment_reward_cents', Types::INTEGER);
            $loyaltySetting->addColumn('is_enabled', Types::BOOLEAN);
            $loyaltySetting->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            $loyaltySetting->addColumn('updated_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $loyaltySetting->setPrimaryKey(['id']);

            foreach ($platform->getCreateTableSQL($loyaltySetting) as $sql) {
                $this->addSql($sql);
            }

            $this->addSql(sprintf(
                'INSERT INTO loyalty_setting (id, appointment_reward_cents, is_enabled, created_at, updated_at) VALUES (1, 200, %s, CURRENT_TIMESTAMP, NULL)',
                $true,
            ));
        }

        if (!$schema->hasTable('loyalty_account')) {
            $loyaltyAccount = new Table('loyalty_account');
            $loyaltyAccount->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
            $loyaltyAccount->addColumn('customer_id', Types::INTEGER);
            $loyaltyAccount->addColumn('available_balance_cents', Types::INTEGER);
            $loyaltyAccount->addColumn('is_active', Types::BOOLEAN);
            $loyaltyAccount->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            $loyaltyAccount->addColumn('updated_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $loyaltyAccount->setPrimaryKey(['id']);
            $loyaltyAccount->addUniqueIndex(['customer_id'], 'UNIQ_LOYALTY_ACCOUNT_CUSTOMER');
            $loyaltyAccount->addForeignKeyConstraint('app_user', ['customer_id'], ['id'], ['onDelete' => 'RESTRICT', 'onUpdate' => 'NO ACTION'], 'FK_LOYALTY_ACCOUNT_CUSTOMER');

            foreach ($platform->getCreateTableSQL($loyaltyAccount) as $sql) {
                $this->addSql($sql);
            }
        }

        if (!$schema->hasTable('loyalty_transaction')) {
            $loyaltyTransaction = new Table('loyalty_transaction');
            $loyaltyTransaction->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
            $loyaltyTransaction->addColumn('account_id', Types::INTEGER);
            $loyaltyTransaction->addColumn('appointment_id', Types::INTEGER, ['notnull' => false]);
            $loyaltyTransaction->addColumn('administrator_id', Types::INTEGER, ['notnull' => false]);
            $loyaltyTransaction->addColumn('amount_cents', Types::INTEGER);
            $loyaltyTransaction->addColumn('type', Types::STRING, ['length' => 40]);
            $loyaltyTransaction->addColumn('status', Types::STRING, ['length' => 40]);
            $loyaltyTransaction->addColumn('reason', Types::TEXT);
            $loyaltyTransaction->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            $loyaltyTransaction->addColumn('validated_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $loyaltyTransaction->addColumn('cancelled_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $loyaltyTransaction->setPrimaryKey(['id']);
            $loyaltyTransaction->addUniqueIndex(['appointment_id'], 'UNIQ_LOYALTY_TRANSACTION_APPOINTMENT');
            $loyaltyTransaction->addIndex(['status'], 'IDX_LOYALTY_TRANSACTION_STATUS');
            $loyaltyTransaction->addIndex(['type'], 'IDX_LOYALTY_TRANSACTION_TYPE');
            $loyaltyTransaction->addIndex(['account_id'], 'IDX_LOYALTY_TRANSACTION_ACCOUNT');
            $loyaltyTransaction->addForeignKeyConstraint('loyalty_account', ['account_id'], ['id'], ['onDelete' => 'CASCADE', 'onUpdate' => 'NO ACTION'], 'FK_LOYALTY_TRANSACTION_ACCOUNT');
            $loyaltyTransaction->addForeignKeyConstraint('appointment', ['appointment_id'], ['id'], ['onDelete' => 'SET NULL', 'onUpdate' => 'NO ACTION'], 'FK_LOYALTY_TRANSACTION_APPOINTMENT');
            $loyaltyTransaction->addForeignKeyConstraint('app_user', ['administrator_id'], ['id'], ['onDelete' => 'SET NULL', 'onUpdate' => 'NO ACTION'], 'FK_LOYALTY_TRANSACTION_ADMINISTRATOR');

            foreach ($platform->getCreateTableSQL($loyaltyTransaction) as $sql) {
                $this->addSql($sql);
            }
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('loyalty_transaction')) {
            $this->addSql('DROP TABLE loyalty_transaction');
        }

        if ($schema->hasTable('loyalty_account')) {
            $this->addSql('DROP TABLE loyalty_account');
        }

        if ($schema->hasTable('loyalty_setting')) {
            $this->addSql('DROP TABLE loyalty_setting');
        }

        if ($schema->hasTable('app_user')) {
            $userTable = $schema->getTable('app_user');

            if ($userTable->hasColumn('is_active')) {
                $this->addSql('ALTER TABLE app_user DROP COLUMN is_active');
            }

            if ($userTable->hasColumn('last_name')) {
                $this->addSql('ALTER TABLE app_user DROP COLUMN last_name');
            }

            if ($userTable->hasColumn('first_name')) {
                $this->addSql('ALTER TABLE app_user DROP COLUMN first_name');
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
