<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add recruitment applications.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('recruitment_application')) {
            return;
        }

        $application = new Table('recruitment_application');
        $application->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $application->addColumn('first_name', Types::STRING, ['length' => 80]);
        $application->addColumn('last_name', Types::STRING, ['length' => 80]);
        $application->addColumn('email', Types::STRING, ['length' => 180]);
        $application->addColumn('phone', Types::STRING, ['length' => 40]);
        $application->addColumn('desired_position', Types::STRING, ['length' => 120]);
        $application->addColumn('availability', Types::STRING, ['length' => 80]);
        $application->addColumn('experience_level', Types::STRING, ['length' => 80]);
        $application->addColumn('message', Types::TEXT);
        $application->addColumn('resume_path', Types::STRING, ['length' => 255, 'notnull' => false]);
        $application->addColumn('status', Types::STRING, ['length' => 40]);
        $application->addColumn('admin_note', Types::TEXT, ['notnull' => false]);
        $application->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $application->addColumn('updated_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $application->addColumn('status_changed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $application->setPrimaryKey(['id']);
        $application->addIndex(['status', 'created_at'], 'IDX_RECRUITMENT_STATUS_CREATED');
        $application->addIndex(['email'], 'IDX_RECRUITMENT_EMAIL');

        foreach ($this->connection->getDatabasePlatform()->getCreateTableSQL($application) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('recruitment_application')) {
            $this->addSql('DROP TABLE recruitment_application');
        }
    }
}
