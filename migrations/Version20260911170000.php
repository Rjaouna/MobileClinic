<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the one-month publication deadline to news articles.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('news_article') || $schema->getTable('news_article')->hasColumn('expires_at')) {
            return;
        }

        $this->addSql('ALTER TABLE news_article ADD COLUMN expires_at DATETIME DEFAULT NULL');
        $this->addSql("UPDATE news_article SET expires_at = datetime(published_at, '+1 month') WHERE expires_at IS NULL");
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('news_article') && $schema->getTable('news_article')->hasColumn('expires_at')) {
            $this->addSql('ALTER TABLE news_article DROP COLUMN expires_at');
        }
    }
}
