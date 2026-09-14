<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace the former SymClinic brand name with Mobile Clinic in persisted content.';
    }

    public function up(Schema $schema): void
    {
        $this->replaceBrand($schema, 'SymClinic', 'Mobile Clinic');
    }

    public function down(Schema $schema): void
    {
        $this->replaceBrand($schema, 'Mobile Clinic', 'SymClinic');
    }

    private function replaceBrand(Schema $schema, string $formerName, string $newName): void
    {
        $targets = [
            'general_setting' => ['legal_profile'],
            'news_article' => ['title', 'content'],
            'product' => ['name', 'description', 'custom_attributes'],
            'recruitment_position' => ['title', 'description', 'rhythm', 'highlights'],
            'recruitment_application' => ['desired_position', 'message', 'admin_note'],
        ];

        foreach ($targets as $tableName => $columns) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }

            $table = $schema->getTable($tableName);

            foreach ($columns as $columnName) {
                if (!$table->hasColumn($columnName)) {
                    continue;
                }

                $this->addSql(
                    sprintf('UPDATE %s SET %s = REPLACE(%s, ?, ?) WHERE %s LIKE ?', $tableName, $columnName, $columnName, $columnName),
                    [$formerName, $newName, '%'.$formerName.'%'],
                );
            }
        }
    }
}
