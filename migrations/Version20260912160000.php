<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912160000 extends AbstractMigration
{
    private const PHONE = '03 20 50 71 03';
    private const ADDRESS = '18 Rue du Sec Arembault, 59800 Lille';
    private const PROFILE_VALUES = [
        'registered_office' => self::ADDRESS,
        'store_address' => self::ADDRESS,
        'business_phone' => self::PHONE,
        'opening_hours_monday' => '09:30–20:00',
        'opening_hours_tuesday' => '09:30–20:00',
        'opening_hours_wednesday' => '09:30–20:00',
        'opening_hours_thursday' => '09:30–20:00',
        'opening_hours_friday' => '09:30–21:00',
        'opening_hours_saturday' => '09:30–21:00',
        'opening_hours_sunday' => '13:30–19:00',
    ];

    public function getDescription(): string
    {
        return 'Set the official Mobile Clinic address, phone number and opening hours.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('general_setting')) {
            $this->updateGeneralSettings($schema, self::PHONE, self::PROFILE_VALUES);
        }

        if ($schema->hasTable('product_reservation') && $schema->getTable('product_reservation')->hasColumn('store_phone')) {
            $this->addSql('UPDATE product_reservation SET store_phone = ?', [self::PHONE]);
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('general_setting')) {
            $profileValues = array_fill_keys(array_keys(self::PROFILE_VALUES), '');
            $this->updateGeneralSettings($schema, '01 00 00 00 00', $profileValues);
        }

        if ($schema->hasTable('product_reservation') && $schema->getTable('product_reservation')->hasColumn('store_phone')) {
            $this->addSql("UPDATE product_reservation SET store_phone = '01 00 00 00 00'");
        }
    }

    /** @param array<string, string> $profileValues */
    private function updateGeneralSettings(Schema $schema, string $phone, array $profileValues): void
    {
        $table = $schema->getTable('general_setting');

        if (!$table->hasColumn('store_phone')) {
            return;
        }

        if (!$table->hasColumn('legal_profile')) {
            $this->addSql('UPDATE general_setting SET store_phone = ?', [$phone]);

            return;
        }

        $rows = $this->connection->fetchAllAssociative('SELECT id, legal_profile FROM general_setting');

        foreach ($rows as $row) {
            $rawProfile = $row['legal_profile'] ?? null;

            if (is_resource($rawProfile)) {
                $rawProfile = stream_get_contents($rawProfile);
            }

            $profile = is_string($rawProfile) && $rawProfile !== '' ? json_decode($rawProfile, true) : [];
            $profile = is_array($profile) ? array_replace($profile, $profileValues) : $profileValues;

            $this->addSql(
                'UPDATE general_setting SET store_phone = ?, legal_profile = ? WHERE id = ?',
                [$phone, json_encode($profile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $row['id']],
            );
        }
    }
}
