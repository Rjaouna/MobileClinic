<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910115000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add editable legal, privacy and social settings.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('general_setting') || $schema->getTable('general_setting')->hasColumn('legal_profile')) {
            return;
        }

        $this->addSql('ALTER TABLE general_setting ADD COLUMN legal_profile CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('general_setting') && $schema->getTable('general_setting')->hasColumn('legal_profile')) {
            $this->addSql('ALTER TABLE general_setting DROP COLUMN legal_profile');
        }
    }
}
