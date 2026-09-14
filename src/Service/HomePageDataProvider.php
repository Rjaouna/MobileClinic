<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\NewsArticleRepository;
use App\Repository\ProductRepository;

final class HomePageDataProvider
{
    public function __construct(
        private readonly AppointmentScheduler $appointmentScheduler,
        private readonly GeneralSettingManager $generalSettingManager,
        private readonly ProductRepository $productRepository,
        private readonly NewsArticleRepository $newsArticleRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public function getData(array $overrides = []): array
    {
        $generalSetting = $this->generalSettingManager->getSetting();

        return array_replace([
            'general_setting' => $generalSetting,
            'home_hero_photo_path' => $generalSetting->getHomeHeroPhotoPath(),
            'home_hero_photo_opacity' => $generalSetting->getHomeHeroPhotoOpacity(),
            'home_hero_title' => $generalSetting->getHomeHeroTitle(),
            'home_hero_highlight' => $generalSetting->getHomeHeroHighlight(),
            'home_hero_description' => $generalSetting->getHomeHeroDescription(),
            'home_promotion_photo_path' => $generalSetting::DEFAULT_HOME_PROMOTION_PHOTO_PATH,
            'latest_products' => array_slice($this->productRepository->findForStore(), 0, 4),
            'latest_news' => $this->newsArticleRepository->findPublished(4),
            'reserved_product_ids' => $this->productRepository->findBlockedProductIds(),
            'devices' => $this->appointmentScheduler->getDeviceChoices(),
            'problems' => $this->appointmentScheduler->getProblemChoices(),
            'slots' => $this->appointmentScheduler->getBookableSlots(),
            'slot_days' => $this->appointmentScheduler->getBookableSlotDays(),
            'services' => [
                [
                    'icon' => 'tool',
                    'title' => 'Réparation rapide',
                    'text' => 'Écran, batterie, connecteur ou diagnostic : choisissez votre créneau en quelques instants.',
                    'href' => '#rendez-vous',
                    'action_label' => 'Réserver',
                ],
                [
                    'icon' => 'bag',
                    'title' => 'Articles en boutique',
                    'text' => 'Mettez un accessoire ou une protection de côté et récupérez-le au moment qui vous arrange.',
                    'href' => '/promotions',
                    'action_label' => 'Voir les articles',
                ],
                [
                    'icon' => 'spark',
                    'title' => 'Fidélité client',
                    'text' => 'Vos avantages restent rattachés à votre espace client après chaque passage.',
                    'href' => '#avantages',
                    'action_label' => 'Découvrir',
                ],
            ],
            'appointment_data' => [],
            'appointment_errors' => [],
            'last_username' => '',
            'login_error' => null,
            'open_modal' => null,
        ], $overrides);
    }
}
