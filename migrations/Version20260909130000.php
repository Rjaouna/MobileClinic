<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add promotional products, shop reservations and general reservation settings.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $true = $this->booleanLiteral(true);

        if (!$schema->hasTable('general_setting')) {
            $setting = new Table('general_setting');
            $setting->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
            $setting->addColumn('store_phone', Types::STRING, ['length' => 40]);
            $setting->addColumn('product_reservation_hold_minutes', Types::INTEGER);
            $setting->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            $setting->addColumn('updated_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $setting->setPrimaryKey(['id']);

            foreach ($platform->getCreateTableSQL($setting) as $sql) {
                $this->addSql($sql);
            }

            $this->addSql("INSERT INTO general_setting (store_phone, product_reservation_hold_minutes, created_at, updated_at) VALUES ('01 00 00 00 00', 1440, CURRENT_TIMESTAMP, NULL)");
        }

        if (!$schema->hasTable('product')) {
            $product = new Table('product');
            $product->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
            $product->addColumn('name', Types::STRING, ['length' => 140]);
            $product->addColumn('normal_price_cents', Types::INTEGER);
            $product->addColumn('promotional_price_cents', Types::INTEGER);
            $product->addColumn('description', Types::TEXT);
            $product->addColumn('photo_path', Types::STRING, ['length' => 255, 'notnull' => false]);
            $product->addColumn('custom_attributes', Types::JSON);
            $product->addColumn('is_active', Types::BOOLEAN, ['default' => true]);
            $product->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            $product->addColumn('updated_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $product->setPrimaryKey(['id']);
            $product->addIndex(['is_active', 'created_at'], 'IDX_PRODUCT_ACTIVE_CREATED');

            foreach ($platform->getCreateTableSQL($product) as $sql) {
                $this->addSql($sql);
            }
        }

        if (!$schema->hasTable('product_reservation')) {
            $reservation = new Table('product_reservation');
            $reservation->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
            $reservation->addColumn('customer_id', Types::INTEGER);
            $reservation->addColumn('status', Types::STRING, ['length' => 40]);
            $reservation->addColumn('total_cents', Types::INTEGER);
            $reservation->addColumn('loyalty_used_cents', Types::INTEGER);
            $reservation->addColumn('loyalty_refunded_cents', Types::INTEGER);
            $reservation->addColumn('payable_cents', Types::INTEGER);
            $reservation->addColumn('store_phone', Types::STRING, ['length' => 40]);
            $reservation->addColumn('expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $reservation->addColumn('confirmed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $reservation->addColumn('cancelled_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $reservation->addColumn('expired_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $reservation->addColumn('admin_note', Types::TEXT, ['notnull' => false]);
            $reservation->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            $reservation->addColumn('updated_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $reservation->setPrimaryKey(['id']);
            $reservation->addIndex(['customer_id'], 'IDX_PRODUCT_RESERVATION_CUSTOMER');
            $reservation->addIndex(['status', 'expires_at'], 'IDX_PRODUCT_RESERVATION_STATUS_EXPIRES');
            $reservation->addIndex(['created_at'], 'IDX_PRODUCT_RESERVATION_CREATED');
            $reservation->addForeignKeyConstraint('app_user', ['customer_id'], ['id'], ['onDelete' => 'RESTRICT', 'onUpdate' => 'NO ACTION'], 'FK_PRODUCT_RESERVATION_CUSTOMER');

            foreach ($platform->getCreateTableSQL($reservation) as $sql) {
                $this->addSql($sql);
            }
        }

        if (!$schema->hasTable('product_reservation_item')) {
            $item = new Table('product_reservation_item');
            $item->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
            $item->addColumn('reservation_id', Types::INTEGER);
            $item->addColumn('product_id', Types::INTEGER, ['notnull' => false]);
            $item->addColumn('product_name', Types::STRING, ['length' => 140]);
            $item->addColumn('photo_path', Types::STRING, ['length' => 255, 'notnull' => false]);
            $item->addColumn('unit_normal_price_cents', Types::INTEGER);
            $item->addColumn('unit_promotional_price_cents', Types::INTEGER);
            $item->addColumn('quantity', Types::INTEGER);
            $item->addColumn('custom_attributes', Types::JSON);
            $item->setPrimaryKey(['id']);
            $item->addIndex(['reservation_id'], 'IDX_PRODUCT_RESERVATION_ITEM_RESERVATION');
            $item->addIndex(['product_id'], 'IDX_PRODUCT_RESERVATION_ITEM_PRODUCT');
            $item->addForeignKeyConstraint('product_reservation', ['reservation_id'], ['id'], ['onDelete' => 'CASCADE', 'onUpdate' => 'NO ACTION'], 'FK_PRODUCT_RESERVATION_ITEM_RESERVATION');
            $item->addForeignKeyConstraint('product', ['product_id'], ['id'], ['onDelete' => 'SET NULL', 'onUpdate' => 'NO ACTION'], 'FK_PRODUCT_RESERVATION_ITEM_PRODUCT');

            foreach ($platform->getCreateTableSQL($item) as $sql) {
                $this->addSql($sql);
            }
        }

        if ($schema->hasTable('loyalty_transaction')) {
            $loyaltyTransaction = $schema->getTable('loyalty_transaction');

            if (!$loyaltyTransaction->hasColumn('product_reservation_id')) {
                $this->addSql('ALTER TABLE loyalty_transaction ADD COLUMN product_reservation_id INTEGER DEFAULT NULL');
            }

            if (!$loyaltyTransaction->hasIndex('IDX_LOYALTY_TRANSACTION_PRODUCT_RESERVATION')) {
                $this->addSql('CREATE INDEX IDX_LOYALTY_TRANSACTION_PRODUCT_RESERVATION ON loyalty_transaction (product_reservation_id)');
            }
        }

        if ($schema->hasTable('product') && !$schema->getTable('product')->hasColumn('is_active')) {
            $this->addSql(sprintf('ALTER TABLE product ADD COLUMN is_active BOOLEAN DEFAULT %s NOT NULL', $true));
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('loyalty_transaction')) {
            $loyaltyTransaction = $schema->getTable('loyalty_transaction');

            if ($loyaltyTransaction->hasIndex('IDX_LOYALTY_TRANSACTION_PRODUCT_RESERVATION')) {
                $this->addSql('DROP INDEX IDX_LOYALTY_TRANSACTION_PRODUCT_RESERVATION');
            }

            if ($loyaltyTransaction->hasColumn('product_reservation_id')) {
                $this->addSql('ALTER TABLE loyalty_transaction DROP COLUMN product_reservation_id');
            }
        }

        if ($schema->hasTable('product_reservation_item')) {
            $this->addSql('DROP TABLE product_reservation_item');
        }

        if ($schema->hasTable('product_reservation')) {
            $this->addSql('DROP TABLE product_reservation');
        }

        if ($schema->hasTable('product')) {
            $this->addSql('DROP TABLE product');
        }

        if ($schema->hasTable('general_setting')) {
            $this->addSql('DROP TABLE general_setting');
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
