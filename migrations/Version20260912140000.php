<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add editable title, highlight and description for the home hero.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('general_setting')) {
            return;
        }

        $table = $schema->getTable('general_setting');

        if (!$table->hasColumn('home_hero_title')) {
            $this->addSql("ALTER TABLE general_setting ADD COLUMN home_hero_title VARCHAR(120) DEFAULT 'Votre téléphone,' NOT NULL");
        }

        if (!$table->hasColumn('home_hero_highlight')) {
            $this->addSql("ALTER TABLE general_setting ADD COLUMN home_hero_highlight VARCHAR(160) DEFAULT 'sans attente inutile.' NOT NULL");
        }

        if (!$table->hasColumn('home_hero_description')) {
            $this->addSql("ALTER TABLE general_setting ADD COLUMN home_hero_description VARCHAR(400) DEFAULT 'Réservez une réparation ou mettez un article de côté en quelques instants. Un service rapide, clair et professionnel.' NOT NULL");
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('general_setting')) {
            return;
        }

        $table = $schema->getTable('general_setting');

        if ($table->hasColumn('home_hero_title')) {
            $this->addSql('ALTER TABLE general_setting DROP COLUMN home_hero_title');
        }

        if ($table->hasColumn('home_hero_highlight')) {
            $this->addSql('ALTER TABLE general_setting DROP COLUMN home_hero_highlight');
        }

        if ($table->hasColumn('home_hero_description')) {
            $this->addSql('ALTER TABLE general_setting DROP COLUMN home_hero_description');
        }
    }
}
