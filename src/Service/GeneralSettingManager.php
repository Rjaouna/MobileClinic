<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GeneralSetting;
use App\Repository\GeneralSettingRepository;
use Doctrine\ORM\EntityManagerInterface;

final class GeneralSettingManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GeneralSettingRepository $settingRepository,
    ) {
    }

    public function getSetting(): GeneralSetting
    {
        $setting = $this->settingRepository->findCurrent();

        if ($setting instanceof GeneralSetting) {
            return $setting;
        }

        $setting = new GeneralSetting();
        $this->entityManager->persist($setting);
        $this->entityManager->flush();

        return $setting;
    }

    public function updateStoreSettings(string $storePhone, int $holdMinutes, int $alertMinutes): GeneralSetting
    {
        if ($holdMinutes < 1) {
            throw new \InvalidArgumentException('La durée de réservation doit être supérieure à zéro minute.');
        }

        if ($alertMinutes < 1 || $alertMinutes > $holdMinutes) {
            throw new \InvalidArgumentException('L’alerte doit être comprise entre 1 minute et la durée totale de réservation.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($storePhone, $holdMinutes, $alertMinutes): GeneralSetting {
            $setting = $this->getSetting();
            $legalProfile = $setting->getLegalProfile();
            $legalProfile['business_phone'] = trim($storePhone);

            return $setting
                ->setStorePhone($storePhone)
                ->setProductReservationHoldMinutes($holdMinutes)
                ->setProductReservationAlertMinutes($alertMinutes)
                ->setLegalProfile($legalProfile);
        });
    }

    public function updateHomepageSettings(
        string $homeHeroPhotoPath,
        int $homeHeroPhotoOpacity,
        string $homeHeroTitle,
        string $homeHeroHighlight,
        string $homeHeroDescription,
    ): GeneralSetting
    {
        return $this->entityManager->wrapInTransaction(function () use ($homeHeroPhotoPath, $homeHeroPhotoOpacity, $homeHeroTitle, $homeHeroHighlight, $homeHeroDescription): GeneralSetting {
            return $this->getSetting()
                ->setHomeHeroPhotoPath($homeHeroPhotoPath)
                ->setHomeHeroPhotoOpacity($homeHeroPhotoOpacity)
                ->setHomeHeroTitle($homeHeroTitle)
                ->setHomeHeroHighlight($homeHeroHighlight)
                ->setHomeHeroDescription($homeHeroDescription);
        });
    }

    public function updateSiteLogo(string $siteLogoPath): GeneralSetting
    {
        return $this->entityManager->wrapInTransaction(function () use ($siteLogoPath): GeneralSetting {
            return $this->getSetting()->setSiteLogoPath($siteLogoPath);
        });
    }

    /** @param array<string, mixed> $legalProfile */
    public function updateLegalSettings(array $legalProfile): GeneralSetting
    {
        return $this->entityManager->wrapInTransaction(function () use ($legalProfile): GeneralSetting {
            $setting = $this->getSetting()->setLegalProfile($legalProfile);
            $businessPhone = trim((string) ($legalProfile['business_phone'] ?? ''));

            if ($businessPhone !== '') {
                $setting->setStorePhone($businessPhone);
            }

            return $setting;
        });
    }
}
