<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add administrable image and YouTube news articles.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('news_article')) {
            return;
        }

        $this->addSql('CREATE TABLE news_article (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(160) NOT NULL, content CLOB NOT NULL, media_type VARCHAR(16) NOT NULL, image_path VARCHAR(500) DEFAULT NULL, youtube_url VARCHAR(500) DEFAULT NULL, is_active BOOLEAN DEFAULT 1 NOT NULL, published_at DATETIME NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX IDX_NEWS_ACTIVE_PUBLISHED ON news_article (is_active, published_at)');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('news_article')) {
            $this->addSql('DROP TABLE news_article');
        }
    }
}
