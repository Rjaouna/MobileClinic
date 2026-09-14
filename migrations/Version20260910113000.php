<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910113000 extends AbstractMigration
{
    private const DEFAULT_HOME_HERO_PHOTO_PATH = 'https://unsplash.com/photos/person-repairing-smartphones-under-a-lighted-table-PZLgTUAhxMM/download?force=true';

    public function getDescription(): string
    {
        return 'Add configurable home hero photo setting.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('general_setting') || $schema->getTable('general_setting')->hasColumn('home_hero_photo_path')) {
            return;
        }

        $defaultPath = $this->connection->quote(self::DEFAULT_HOME_HERO_PHOTO_PATH);
        $this->addSql(sprintf('ALTER TABLE general_setting ADD COLUMN home_hero_photo_path VARCHAR(500) DEFAULT %s NOT NULL', $defaultPath));
        $this->addSql(sprintf('UPDATE general_setting SET home_hero_photo_path = %s WHERE home_hero_photo_path IS NULL OR home_hero_photo_path = \'\'', $defaultPath));
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('general_setting') && $schema->getTable('general_setting')->hasColumn('home_hero_photo_path')) {
            $this->addSql('ALTER TABLE general_setting DROP COLUMN home_hero_photo_path');
        }
    }
}
