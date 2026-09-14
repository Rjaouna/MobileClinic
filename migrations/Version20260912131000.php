<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912131000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Use the client-approved Mobile Clinic technician visual as the home hero background.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('general_setting') || !$schema->getTable('general_setting')->hasColumn('home_hero_photo_path')) {
            return;
        }

        $this->addSql("UPDATE general_setting SET home_hero_photo_path = '/images/home-hero-technician-v2.png', home_hero_photo_opacity = 100");
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('general_setting') || !$schema->getTable('general_setting')->hasColumn('home_hero_photo_path')) {
            return;
        }

        $this->addSql("UPDATE general_setting SET home_hero_photo_path = 'https://unsplash.com/photos/person-repairing-smartphones-under-a-lighted-table-PZLgTUAhxMM/download?force=true', home_hero_photo_opacity = 100");
    }
}
