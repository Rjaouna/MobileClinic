<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909135000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sold product and shop reservation withdrawal tracking.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('product') && !$schema->getTable('product')->hasColumn('sold_at')) {
            $this->addSql('ALTER TABLE product ADD COLUMN sold_at DATETIME DEFAULT NULL');
        }

        if ($schema->hasTable('product_reservation') && !$schema->getTable('product_reservation')->hasColumn('withdrawn_at')) {
            $this->addSql('ALTER TABLE product_reservation ADD COLUMN withdrawn_at DATETIME DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('product_reservation') && $schema->getTable('product_reservation')->hasColumn('withdrawn_at')) {
            $this->addSql('ALTER TABLE product_reservation DROP COLUMN withdrawn_at');
        }

        if ($schema->hasTable('product') && $schema->getTable('product')->hasColumn('sold_at')) {
            $this->addSql('ALTER TABLE product DROP COLUMN sold_at');
        }
    }
}
