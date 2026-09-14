<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the configurable warning threshold for product reservation expiration.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('general_setting') || $schema->getTable('general_setting')->hasColumn('product_reservation_alert_minutes')) {
            return;
        }

        $this->addSql('ALTER TABLE general_setting ADD COLUMN product_reservation_alert_minutes INTEGER DEFAULT 120 NOT NULL');
        $this->addSql('UPDATE general_setting SET product_reservation_alert_minutes = product_reservation_hold_minutes WHERE product_reservation_alert_minutes > product_reservation_hold_minutes');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('general_setting') || !$schema->getTable('general_setting')->hasColumn('product_reservation_alert_minutes')) {
            return;
        }

        $this->addSql('ALTER TABLE general_setting DROP COLUMN product_reservation_alert_minutes');
    }
}
