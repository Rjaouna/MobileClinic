<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GeneralSettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GeneralSettingRepository::class)]
#[ORM\Table(name: 'general_setting')]
class GeneralSetting
{
    public const DEFAULT_ID = 1;
    public const DEFAULT_STORE_PHONE = '03 20 50 71 03';
    public const DEFAULT_STORE_ADDRESS = '18 Rue du Sec Arembault, 59800 Lille';
    public const DEFAULT_GOOGLE_BUSINESS_URL = 'https://share.google/mUlac1hXzMCLRVTds';
    public const DEFAULT_OPENING_HOURS = [
        'monday' => '09:30–20:00',
        'tuesday' => '09:30–20:00',
        'wednesday' => '09:30–20:00',
        'thursday' => '09:30–20:00',
        'friday' => '09:30–21:00',
        'saturday' => '09:30–21:00',
        'sunday' => '13:30–19:00',
    ];
    public const DEFAULT_HOME_HERO_PHOTO_PATH = '/images/home-hero-technician-v2.png';
    public const DEFAULT_HOME_HERO_TITLE = 'Votre téléphone,';
    public const DEFAULT_HOME_HERO_HIGHLIGHT = 'sans attente inutile.';
    public const DEFAULT_HOME_HERO_DESCRIPTION = 'Réservez une réparation ou mettez un article de côté en quelques instants. Un service rapide, clair et professionnel.';
    public const DEFAULT_HOME_PROMOTION_PHOTO_PATH = '/images/home-promotions-lifestyle-v3.webp';
    public const DEFAULT_LEGAL_PROFILE = [
        'company_name' => 'Mobile Clinic',
        'trade_name' => 'Mobile Clinic',
        'legal_form' => '',
        'capital' => '',
        'registered_office' => self::DEFAULT_STORE_ADDRESS,
        'store_address' => self::DEFAULT_STORE_ADDRESS,
        'google_business_url' => self::DEFAULT_GOOGLE_BUSINESS_URL,
        'siret' => '',
        'vat_number' => '',
        'rcs' => '',
        'legal_representative' => '',
        'publication_director' => '',
        'business_email' => '',
        'business_phone' => self::DEFAULT_STORE_PHONE,
        'opening_hours_monday' => '09:30–20:00',
        'opening_hours_tuesday' => '09:30–20:00',
        'opening_hours_wednesday' => '09:30–20:00',
        'opening_hours_thursday' => '09:30–20:00',
        'opening_hours_friday' => '09:30–21:00',
        'opening_hours_saturday' => '09:30–21:00',
        'opening_hours_sunday' => '13:30–19:00',
        'site_url' => '',
        'domain_name' => '',
        'host_name' => '',
        'host_address' => '',
        'host_phone' => '',
        'maintainer' => '',
        'privacy_email' => '',
        'dpo' => '',
        'data_recipients' => 'Personnel habilité de Mobile Clinic et prestataires techniques strictement nécessaires.',
        'processors' => '',
        'third_party_tools' => 'Aucun outil analytique ou publicitaire activé à ce jour.',
        'customer_retention_months' => '60',
        'appointment_retention_months' => '60',
        'recruitment_retention_months' => '24',
        'loyalty_retention_months' => '60',
        'outside_eu_transfer' => '0',
        'outside_eu_details' => '',
        'cookie_consent_tool' => 'Aucun : seuls des cookies strictement nécessaires sont actuellement utilisés.',
        'analytics_cookies' => '0',
        'advertising_cookies' => '0',
        'facebook_url' => '',
        'instagram_url' => '',
        'tiktok_url' => '',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $storePhone = self::DEFAULT_STORE_PHONE;

    #[ORM\Column]
    private int $productReservationHoldMinutes = 1440;

    #[ORM\Column(options: ['default' => 120])]
    private int $productReservationAlertMinutes = 120;

    #[ORM\Column(length: 500)]
    private string $homeHeroPhotoPath = self::DEFAULT_HOME_HERO_PHOTO_PATH;

    #[ORM\Column(options: ['default' => 100])]
    private int $homeHeroPhotoOpacity = 100;

    #[ORM\Column(length: 120, options: ['default' => self::DEFAULT_HOME_HERO_TITLE])]
    private string $homeHeroTitle = self::DEFAULT_HOME_HERO_TITLE;

    #[ORM\Column(length: 160, options: ['default' => self::DEFAULT_HOME_HERO_HIGHLIGHT])]
    private string $homeHeroHighlight = self::DEFAULT_HOME_HERO_HIGHLIGHT;

    #[ORM\Column(length: 400, options: ['default' => self::DEFAULT_HOME_HERO_DESCRIPTION])]
    private string $homeHeroDescription = self::DEFAULT_HOME_HERO_DESCRIPTION;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $siteLogoPath = null;

    /** @var array<string, string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $legalProfile = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStorePhone(): string
    {
        return $this->storePhone;
    }

    public function setStorePhone(string $storePhone): self
    {
        $this->storePhone = trim($storePhone) !== '' ? trim($storePhone) : self::DEFAULT_STORE_PHONE;
        $this->touch();

        return $this;
    }

    public function getProductReservationHoldMinutes(): int
    {
        return $this->productReservationHoldMinutes;
    }

    public function setProductReservationHoldMinutes(int $productReservationHoldMinutes): self
    {
        $this->productReservationHoldMinutes = max(1, $productReservationHoldMinutes);
        $this->touch();

        return $this;
    }

    public function getProductReservationAlertMinutes(): int
    {
        return max(1, min($this->productReservationAlertMinutes, $this->productReservationHoldMinutes));
    }

    public function setProductReservationAlertMinutes(int $productReservationAlertMinutes): self
    {
        $this->productReservationAlertMinutes = max(1, $productReservationAlertMinutes);
        $this->touch();

        return $this;
    }

    public function getProductReservationHoldLabel(): string
    {
        if ($this->productReservationHoldMinutes % 1440 === 0) {
            $days = intdiv($this->productReservationHoldMinutes, 1440);

            return sprintf('%d journée%s', $days, $days > 1 ? 's' : '');
        }

        if ($this->productReservationHoldMinutes % 60 === 0) {
            $hours = intdiv($this->productReservationHoldMinutes, 60);

            return sprintf('%d heure%s', $hours, $hours > 1 ? 's' : '');
        }

        return sprintf('%d minute%s', $this->productReservationHoldMinutes, $this->productReservationHoldMinutes > 1 ? 's' : '');
    }

    public function getHomeHeroPhotoPath(): string
    {
        return $this->homeHeroPhotoPath;
    }

    public function setHomeHeroPhotoPath(string $homeHeroPhotoPath): self
    {
        $homeHeroPhotoPath = trim($homeHeroPhotoPath);
        $this->homeHeroPhotoPath = $homeHeroPhotoPath !== ''
            ? mb_substr($homeHeroPhotoPath, 0, 500)
            : self::DEFAULT_HOME_HERO_PHOTO_PATH;
        $this->touch();

        return $this;
    }

    public function getHomeHeroPhotoOpacity(): int
    {
        return $this->homeHeroPhotoOpacity;
    }

    public function setHomeHeroPhotoOpacity(int $homeHeroPhotoOpacity): self
    {
        $this->homeHeroPhotoOpacity = max(0, min(100, $homeHeroPhotoOpacity));
        $this->touch();

        return $this;
    }

    public function getHomeHeroTitle(): string
    {
        return $this->homeHeroTitle;
    }

    public function setHomeHeroTitle(string $homeHeroTitle): self
    {
        $homeHeroTitle = trim($homeHeroTitle);
        $this->homeHeroTitle = mb_substr($homeHeroTitle !== '' ? $homeHeroTitle : self::DEFAULT_HOME_HERO_TITLE, 0, 120);
        $this->touch();

        return $this;
    }

    public function getHomeHeroHighlight(): string
    {
        return $this->homeHeroHighlight;
    }

    public function setHomeHeroHighlight(string $homeHeroHighlight): self
    {
        $homeHeroHighlight = trim($homeHeroHighlight);
        $this->homeHeroHighlight = mb_substr($homeHeroHighlight !== '' ? $homeHeroHighlight : self::DEFAULT_HOME_HERO_HIGHLIGHT, 0, 160);
        $this->touch();

        return $this;
    }

    public function getHomeHeroDescription(): string
    {
        return $this->homeHeroDescription;
    }

    public function setHomeHeroDescription(string $homeHeroDescription): self
    {
        $homeHeroDescription = trim($homeHeroDescription);
        $this->homeHeroDescription = mb_substr($homeHeroDescription !== '' ? $homeHeroDescription : self::DEFAULT_HOME_HERO_DESCRIPTION, 0, 400);
        $this->touch();

        return $this;
    }

    public function getSiteLogoPath(): ?string
    {
        return $this->siteLogoPath;
    }

    public function setSiteLogoPath(?string $siteLogoPath): self
    {
        $siteLogoPath = trim((string) $siteLogoPath);
        $this->siteLogoPath = $siteLogoPath !== '' ? mb_substr($siteLogoPath, 0, 500) : null;
        $this->touch();

        return $this;
    }

    /** @return array<string, string> */
    public function getLegalProfile(): array
    {
        return array_replace(self::DEFAULT_LEGAL_PROFILE, $this->legalProfile ?? []);
    }

    public function getStoreAddress(): string
    {
        $profile = $this->getLegalProfile();

        return trim($profile['store_address']) !== ''
            ? $profile['store_address']
            : (trim($profile['registered_office']) !== '' ? $profile['registered_office'] : self::DEFAULT_STORE_ADDRESS);
    }

    public function getGoogleBusinessUrl(): string
    {
        $url = trim((string) ($this->getLegalProfile()['google_business_url'] ?? ''));

        return $url !== '' ? $url : self::DEFAULT_GOOGLE_BUSINESS_URL;
    }

    /** @return array<string, string> */
    public function getStoreOpeningHours(): array
    {
        $profile = $this->getLegalProfile();
        $hours = [];

        foreach (self::DEFAULT_OPENING_HOURS as $day => $defaultHours) {
            $value = trim((string) ($profile['opening_hours_'.$day] ?? ''));
            $hours[$day] = $value !== '' ? $value : $defaultHours;
        }

        return $hours;
    }

    public function getStoreOpeningHoursSummary(): string
    {
        $labels = [
            'monday' => 'Lun.',
            'tuesday' => 'Mar.',
            'wednesday' => 'Mer.',
            'thursday' => 'Jeu.',
            'friday' => 'Ven.',
            'saturday' => 'Sam.',
            'sunday' => 'Dim.',
        ];
        $groups = [];
        $groupStart = null;
        $groupEnd = null;
        $groupHours = null;

        foreach ($this->getStoreOpeningHours() as $day => $hours) {
            if ($groupHours !== null && $hours !== $groupHours) {
                $groups[] = $this->formatOpeningHoursGroup($labels, (string) $groupStart, (string) $groupEnd, $groupHours);
                $groupStart = $day;
            }

            $groupStart ??= $day;
            $groupEnd = $day;
            $groupHours = $hours;
        }

        if ($groupHours !== null) {
            $groups[] = $this->formatOpeningHoursGroup($labels, (string) $groupStart, (string) $groupEnd, $groupHours);
        }

        return implode(' · ', $groups);
    }

    /** @param array<string, mixed> $legalProfile */
    public function setLegalProfile(array $legalProfile): self
    {
        $normalized = [];

        foreach (self::DEFAULT_LEGAL_PROFILE as $field => $defaultValue) {
            $value = trim((string) ($legalProfile[$field] ?? $defaultValue));
            $normalized[$field] = mb_substr($value, 0, 3000);
        }

        $this->legalProfile = $normalized;
        $this->touch();

        return $this;
    }

    /** @param array<string, string> $labels */
    private function formatOpeningHoursGroup(array $labels, string $start, string $end, string $hours): string
    {
        $dayLabel = $start === $end ? $labels[$start] : $labels[$start].' - '.mb_strtolower($labels[$end]);

        return $dayLabel.' '.$hours;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
