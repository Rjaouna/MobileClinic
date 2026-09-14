<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add administrable recruitment positions and link applications to their position.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('recruitment_position')) {
            $this->addSql('CREATE TABLE recruitment_position (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(140) NOT NULL, category VARCHAR(40) NOT NULL, description CLOB NOT NULL, rhythm VARCHAR(120) NOT NULL, highlights CLOB NOT NULL, is_active BOOLEAN DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_RECRUITMENT_POSITION_TITLE ON recruitment_position (title)');
            $this->addSql('CREATE INDEX IDX_RECRUITMENT_POSITION_ACTIVE_CREATED ON recruitment_position (is_active, created_at)');

            $this->addSql("INSERT INTO recruitment_position (title, category, description, rhythm, highlights, is_active, created_at) VALUES ('Technicien réparation mobile', 'Atelier', 'Diagnostiquez les appareils et réalisez des réparations propres, documentées et contrôlées.', 'Temps plein · Selon profil', '[\"Diagnostic et réparation\",\"Contrôle qualité\",\"Organisation de l’atelier\"]', 1, CURRENT_TIMESTAMP)");
            $this->addSql("INSERT INTO recruitment_position (title, category, description, rhythm, highlights, is_active, created_at) VALUES ('Conseiller boutique', 'Boutique', 'Accueillez les clients, qualifiez leurs besoins et suivez chaque demande avec clarté.', 'Temps plein · Selon profil', '[\"Accueil et conseil\",\"Suivi des dossiers\",\"Vente d’accessoires\"]', 1, CURRENT_TIMESTAMP)");
            $this->addSql("INSERT INTO recruitment_position (title, category, description, rhythm, highlights, is_active, created_at) VALUES ('Responsable boutique', 'Pilotage', 'Coordonnez l’activité, accompagnez l’équipe et garantissez une expérience client constante.', 'Temps plein · Expérimenté', '[\"Pilotage quotidien\",\"Animation d’équipe\",\"Qualité de service\"]', 1, CURRENT_TIMESTAMP)");
        }

        if ($schema->hasTable('recruitment_application') && !$schema->getTable('recruitment_application')->hasColumn('position_id')) {
            $this->addSql('ALTER TABLE recruitment_application ADD COLUMN position_id INTEGER DEFAULT NULL');
            $this->addSql('CREATE INDEX IDX_RECRUITMENT_APPLICATION_POSITION ON recruitment_application (position_id)');
            $this->addSql('UPDATE recruitment_application SET position_id = (SELECT recruitment_position.id FROM recruitment_position WHERE recruitment_position.title = recruitment_application.desired_position LIMIT 1) WHERE desired_position != \'Candidature spontanée\'');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('recruitment_application') && $schema->getTable('recruitment_application')->hasColumn('position_id')) {
            $this->addSql('DROP INDEX IF EXISTS IDX_RECRUITMENT_APPLICATION_POSITION');
            $this->addSql('ALTER TABLE recruitment_application DROP COLUMN position_id');
        }

        if ($schema->hasTable('recruitment_position')) {
            $this->addSql('DROP TABLE recruitment_position');
        }
    }
}
