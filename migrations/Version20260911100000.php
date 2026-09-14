<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the configurable public site logo.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('general_setting') || $schema->getTable('general_setting')->hasColumn('site_logo_path')) {
            return;
        }

        $this->addSql('ALTER TABLE general_setting ADD COLUMN site_logo_path VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('general_setting') && $schema->getTable('general_setting')->hasColumn('site_logo_path')) {
            $this->addSql('ALTER TABLE general_setting DROP COLUMN site_logo_path');
        }
    }
}
