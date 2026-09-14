<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910214000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add configurable opacity for the home hero photo.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('general_setting') || $schema->getTable('general_setting')->hasColumn('home_hero_photo_opacity')) {
            return;
        }

        $this->addSql('ALTER TABLE general_setting ADD COLUMN home_hero_photo_opacity INTEGER DEFAULT 100 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('general_setting') && $schema->getTable('general_setting')->hasColumn('home_hero_photo_opacity')) {
            $this->addSql('ALTER TABLE general_setting DROP COLUMN home_hero_photo_opacity');
        }
    }
}
