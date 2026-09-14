<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912170000 extends AbstractMigration
{
    private const GOOGLE_BUSINESS_URL = 'https://share.google/mUlac1hXzMCLRVTds';

    public function getDescription(): string
    {
        return 'Configure the Google Business listing used by the footer and contact page.';
    }

    public function up(Schema $schema): void
    {
        $this->updateGoogleBusinessUrl($schema, self::GOOGLE_BUSINESS_URL);
    }

    public function down(Schema $schema): void
    {
        $this->updateGoogleBusinessUrl($schema, '');
    }

    private function updateGoogleBusinessUrl(Schema $schema, string $url): void
    {
        if (!$schema->hasTable('general_setting') || !$schema->getTable('general_setting')->hasColumn('legal_profile')) {
            return;
        }

        foreach ($this->connection->fetchAllAssociative('SELECT id, legal_profile FROM general_setting') as $row) {
            $rawProfile = $row['legal_profile'] ?? null;

            if (is_resource($rawProfile)) {
                $rawProfile = stream_get_contents($rawProfile);
            }

            $profile = is_string($rawProfile) && $rawProfile !== '' ? json_decode($rawProfile, true) : [];
            $profile = is_array($profile) ? $profile : [];
            $profile['google_business_url'] = $url;

            $this->addSql(
                'UPDATE general_setting SET legal_profile = ? WHERE id = ?',
                [json_encode($profile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $row['id']],
            );
        }
    }
}
