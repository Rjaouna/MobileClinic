<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a unique phone number to customer accounts.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SQLitePlatform, 'Cette migration est prévue pour SQLite.');

        $this->addSql('ALTER TABLE app_user ADD COLUMN phone VARCHAR(40) DEFAULT NULL');
        $this->addSql("CREATE TEMPORARY TABLE __temp__user_phone AS SELECT customer_id, MIN(normalized_phone) AS phone FROM (SELECT customer_id, CASE WHEN raw_phone LIKE '0_________' THEN '+33' || SUBSTR(raw_phone, 2) WHEN raw_phone LIKE '00%' THEN '+' || SUBSTR(raw_phone, 3) ELSE raw_phone END AS normalized_phone FROM (SELECT customer_id, REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(phone), ' ', ''), '.', ''), '-', ''), '(', ''), ')', '') AS raw_phone FROM appointment WHERE phone IS NOT NULL AND TRIM(phone) != '')) WHERE normalized_phone != '' GROUP BY customer_id");
        $this->addSql('UPDATE app_user SET phone = (SELECT phone FROM __temp__user_phone WHERE __temp__user_phone.customer_id = app_user.id) WHERE id IN (SELECT candidate.customer_id FROM __temp__user_phone candidate WHERE (SELECT COUNT(DISTINCT duplicate.customer_id) FROM __temp__user_phone duplicate WHERE duplicate.phone = candidate.phone) = 1)');
        $this->addSql('DROP TABLE __temp__user_phone');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_USER_PHONE ON app_user (phone)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_USER_PHONE');
        $this->addSql('CREATE TEMPORARY TABLE __temp__app_user AS SELECT id, email, roles, password, must_change_password, temporary_password_issued_at, created_at, updated_at FROM app_user');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('CREATE TABLE app_user (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles CLOB NOT NULL, password VARCHAR(255) NOT NULL, must_change_password BOOLEAN NOT NULL, temporary_password_issued_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL)');
        $this->addSql('INSERT INTO app_user (id, email, roles, password, must_change_password, temporary_password_issued_at, created_at, updated_at) SELECT id, email, roles, password, must_change_password, temporary_password_issued_at, created_at, updated_at FROM __temp__app_user');
        $this->addSql('DROP TABLE __temp__app_user');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL ON app_user (email)');
    }
}
