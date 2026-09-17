<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Appointment;
use App\Entity\AppointmentAvailability;
use App\Entity\GeneralSetting;
use App\Entity\ProductReservation;
use App\Repository\AppointmentRepository;
use App\Repository\LoyaltyAccountRepository;
use App\Repository\NewsArticleRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductReservationRepository;
use App\Repository\RecruitmentApplicationRepository;
use App\Repository\UserRepository;
use App\Service\AppointmentReminderManager;
use App\Service\AppointmentScheduler;
use App\Service\GeneralSettingManager;
use App\Service\ProductReservationManager;
use App\Service\StoreCheckInManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly AppointmentRepository $appointmentRepository,
        private readonly AppointmentReminderManager $appointmentReminderManager,
        private readonly AppointmentScheduler $appointmentScheduler,
        private readonly GeneralSettingManager $generalSettingManager,
        private readonly ProductReservationManager $productReservationManager,
        private readonly UserRepository $userRepository,
        private readonly LoyaltyAccountRepository $loyaltyAccountRepository,
        private readonly ProductRepository $productRepository,
        private readonly ProductReservationRepository $productReservationRepository,
        private readonly RecruitmentApplicationRepository $recruitmentApplicationRepository,
        private readonly NewsArticleRepository $newsArticleRepository,
        private readonly StoreCheckInManager $storeCheckInManager,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/admin', name: 'app_admin_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $this->productReservationManager->expireOverdueReservations();
        $reminderView = $this->appointmentReminderManager->buildAdminView(6);
        $weekDays = $this->appointmentScheduler->getAvailabilityWeekOverview();
        $visibleRanges = array_sum(array_map(
            static fn (array $day): int => (int) $day['enabled_ranges'],
            $weekDays,
        ));

        return $this->render('admin/dashboard/index.html.twig', [
            'user_email' => $this->getUser()?->getUserIdentifier(),
            'loyalty_totals' => $this->loyaltyAccountRepository->getTotals(),
            'reminder_view' => $reminderView,
            'check_in_view' => $this->storeCheckInManager->buildAdminView(8),
            'reminder_appointments' => array_map(static fn (array $item): Appointment => $item['appointment'], $reminderView['items']),
            'expired_news' => $this->newsArticleRepository->findExpiredActive(),
            'expired_news' => $this->newsArticleRepository->findExpiredActive(),
            'status_labels' => Appointment::STATUS_LABELS,
            'admin_status_choices' => $this->appointmentReminderManager->getAdminStatusChoices(),
            'status_consequences' => $this->appointmentReminderManager->getStatusConsequences(),
            'stats' => [
                'customers' => $this->userRepository->countCustomers(),
                'active_appointments' => $this->appointmentRepository->countByStatuses(Appointment::ACTIVE_STATUSES),
                'appointments' => $this->appointmentRepository->countUpcoming(),
                'pending' => $this->appointmentRepository->countByStatus(Appointment::STATUS_PENDING),
                'interventions' => $reminderView['total'],
                'completed' => $this->appointmentRepository->countByStatus(Appointment::STATUS_COMPLETED),
                'no_show' => $this->appointmentRepository->countByStatus(Appointment::STATUS_NO_SHOW),
                'products' => count($this->productRepository->findForAdmin('', 'active')),
                'product_reservations' => $this->productReservationRepository->countByStatuses(ProductReservation::ACTIVE_STATUSES),
                'sold_products' => $this->productRepository->countSold(),
                'recruitment_open' => $this->recruitmentApplicationRepository->countOpen(),
                'recruitment_total' => $this->recruitmentApplicationRepository->countAllApplications(),
                'news' => $this->newsArticleRepository->countPublished(),
                'news_expired' => $this->newsArticleRepository->countExpiredActive(),
                'news_expired' => $this->newsArticleRepository->countExpiredActive(),
                'loyalty_accounts' => $this->loyaltyAccountRepository->countAccounts(),
                'visible_ranges' => $visibleRanges,
            ],
        ]);
    }

    #[Route('/admin/parametres-generaux', name: 'app_admin_settings_index', methods: ['GET'])]
    public function settings(): Response
    {
        return $this->render('admin/dashboard/settings.html.twig', $this->buildSettingsViewData());
    }

    #[Route('/admin/parametres-generaux/jours/{day}', name: 'app_admin_settings_day', requirements: ['day' => '[1-7]'], methods: ['GET'])]
    public function settingsDay(int $day): Response
    {
        return $this->render('admin/dashboard/settings.html.twig', $this->buildSettingsViewData($day));
    }

    #[Route('/admin/parametres-generaux/boutique', name: 'app_admin_settings_store_update', methods: ['POST'])]
    public function updateStoreSettings(Request $request): RedirectResponse
    {
        $this->denyUnlessValidCsrf('admin_general_settings', $request);

        try {
            $this->generalSettingManager->updateStoreSettings(
                (string) $request->request->get('store_phone'),
                (int) $request->request->get('product_reservation_hold_minutes', 1440),
                (int) $request->request->get('product_reservation_alert_minutes', 120),
            );
            $this->productReservationManager->expireOverdueReservations();
            $this->addFlash('success', 'Les paramètres boutique ont été mis à jour.');
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirect($this->generateUrl('app_admin_settings_index').'#store-settings-title');
    }

    #[Route('/admin/parametres-generaux/accueil', name: 'app_admin_settings_homepage_update', methods: ['POST'])]
    public function updateHomepageSettings(Request $request): RedirectResponse
    {
        $this->denyUnlessValidCsrf('admin_homepage_settings', $request);

        try {
            $photoPath = $this->handleHomepagePhoto($request, $this->generalSettingManager->getSetting()->getHomeHeroPhotoPath());
            $photoOpacity = $this->validateHomepagePhotoOpacity($request->request->get('home_hero_photo_opacity', 100));
            [$title, $highlight, $description] = $this->validateHomepageCopy($request);
            $this->generalSettingManager->updateHomepageSettings($photoPath, $photoOpacity, $title, $highlight, $description);
            $this->addFlash('success', 'Le contenu et la photo du premier bloc ont été mis à jour.');
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirect($this->generateUrl('app_admin_settings_index').'#homepage-settings-title');
    }

    #[Route('/admin/parametres-generaux/logo', name: 'app_admin_settings_logo_update', methods: ['POST'])]
    public function updateSiteLogo(Request $request): RedirectResponse
    {
        $this->denyUnlessValidCsrf('admin_site_logo_settings', $request);

        try {
            $setting = $this->generalSettingManager->getSetting();
            $logoPath = $this->handleSiteLogo($request, $setting->getSiteLogoPath());
            $this->generalSettingManager->updateSiteLogo($logoPath);
            $this->addFlash('success', 'Le logo du site a été mis à jour.');
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirect($this->generateUrl('app_admin_settings_index').'#site-logo-settings-title');
    }

    #[Route('/admin/parametres-generaux/informations-legales', name: 'app_admin_settings_legal_update', methods: ['POST'])]
    public function updateLegalSettings(Request $request): RedirectResponse
    {
        $this->denyUnlessValidCsrf('admin_legal_settings', $request);
        $legalProfile = $request->request->all('legal');

        try {
            $this->validateLegalUrls($legalProfile);
            $this->generalSettingManager->updateLegalSettings($legalProfile);
            $this->addFlash('success', 'Les informations légales et les liens du footer ont été mis à jour.');
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirect($this->generateUrl('app_admin_settings_index').'#legal-settings-title');
    }

    #[Route('/admin/parametres-generaux/presence-magasin', name: 'app_admin_settings_check_in_update', methods: ['POST'])]
    public function updateCheckInSettings(Request $request): RedirectResponse
    {
        $this->denyUnlessValidCsrf('admin_store_check_in_settings', $request);

        try {
            $this->storeCheckInManager->updateSettings(
                $request->request->getBoolean('store_check_in_enabled'),
                (int) $request->request->get('store_check_in_expiration_minutes', 15),
            );
            $this->addFlash('success', 'Les paramètres de présence magasin ont été mis à jour.');
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirect($this->generateUrl('app_admin_settings_index').'#settings-check-in');
    }

    #[Route('/admin/parametres-generaux/presence-magasin/regenerer', name: 'app_admin_settings_check_in_regenerate', methods: ['POST'])]
    public function regenerateCheckInQrCode(Request $request): RedirectResponse
    {
        $this->denyUnlessValidCsrf('admin_store_check_in_regenerate', $request);
        $this->storeCheckInManager->regenerateToken();
        $this->addFlash('success', 'Le QR code magasin a été régénéré. L’ancien code est désormais inutilisable.');

        return $this->redirect($this->generateUrl('app_admin_settings_index').'#settings-check-in');
    }

    #[Route('/admin/parametres-generaux/presence-magasin/qrcode.svg', name: 'app_admin_settings_check_in_qr_download', methods: ['GET'])]
    public function downloadCheckInQrCode(): Response
    {
        return new Response($this->storeCheckInManager->buildQrCodeSvg(), Response::HTTP_OK, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="mobile-clinic-presence-magasin.svg"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSettingsViewData(?int $selectedDay = null): array
    {
        $data = [
            'general_setting' => $this->generalSettingManager->getSetting(),
            'appointment_setting' => $this->appointmentScheduler->getSetting(),
            'week_days' => $this->appointmentScheduler->getAvailabilityWeekOverview(),
            'availability_ranges_by_day' => $this->appointmentScheduler->getAvailabilityRangesByDay(),
            'days' => $this->appointmentScheduler->getDays(),
            'selected_day' => null,
            'store_check_in' => $this->storeCheckInManager->buildQrCode(),
        ];

        if ($selectedDay !== null && isset(AppointmentAvailability::DAYS[$selectedDay])) {
            $data['selected_day'] = $selectedDay;
            $data['selected_day_label'] = $this->appointmentScheduler->getDays()[$selectedDay];
            $data['selected_day_summary'] = $this->appointmentScheduler->getAvailabilityDayOverview($selectedDay);
            $data['selected_day_availabilities'] = $this->appointmentScheduler->getAvailabilitiesForDay($selectedDay);
        }

        return $data;
    }

    private function denyUnlessValidCsrf(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }

    private function handleHomepagePhoto(Request $request, string $currentPath): string
    {
        $file = $request->files->get('home_hero_photo');

        if (!$file instanceof UploadedFile || $file->getError() === UPLOAD_ERR_NO_FILE) {
            $currentPath = trim($currentPath);

            return $currentPath !== '' ? $currentPath : GeneralSetting::DEFAULT_HOME_HERO_PHOTO_PATH;
        }

        if (!$file->isValid()) {
            throw new \InvalidArgumentException('La photo d’accueil n’a pas pu être envoyée correctement.');
        }

        $extension = mb_strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());

        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            throw new \InvalidArgumentException('La photo d’accueil doit être une image JPG, PNG ou WebP.');
        }

        $directory = $this->projectDir.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'site';

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \InvalidArgumentException('Impossible de créer le dossier des photos du site.');
        }

        $filename = 'accueil-'.bin2hex(random_bytes(10)).'.'.$extension;
        $file->move($directory, $filename);

        return '/uploads/site/'.$filename;
    }

    private function handleSiteLogo(Request $request, ?string $currentPath): string
    {
        $file = $request->files->get('site_logo');

        if (!$file instanceof UploadedFile || $file->getError() === UPLOAD_ERR_NO_FILE) {
            if ($currentPath !== null && trim($currentPath) !== '') {
                return $currentPath;
            }

            throw new \InvalidArgumentException('Choisissez un logo au format PNG ou JPG.');
        }

        if (!$file->isValid()) {
            throw new \InvalidArgumentException('Le logo n’a pas pu être envoyé correctement.');
        }

        if (($file->getSize() ?: 0) > 4 * 1024 * 1024) {
            throw new \InvalidArgumentException('Le logo ne doit pas dépasser 4 Mo.');
        }

        $extension = mb_strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());

        if (!in_array($extension, ['jpg', 'jpeg', 'png'], true) || @getimagesize($file->getPathname()) === false) {
            throw new \InvalidArgumentException('Le logo doit être une image PNG ou JPG valide.');
        }

        $directory = $this->projectDir.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'site';

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \InvalidArgumentException('Impossible de créer le dossier du logo.');
        }

        $filename = 'logo-'.bin2hex(random_bytes(10)).'.'.($extension === 'jpeg' ? 'jpg' : $extension);
        $file->move($directory, $filename);

        return '/uploads/site/'.$filename;
    }

    private function validateHomepagePhotoOpacity(mixed $value): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException('L’opacité de la photo doit être un nombre entier entre 0 et 100 %.');
        }

        $opacity = (int) $value;

        if ($opacity < 0 || $opacity > 100) {
            throw new \InvalidArgumentException('L’opacité de la photo doit être comprise entre 0 et 100 %.');
        }

        return $opacity;
    }

    /** @return array{string, string, string} */
    private function validateHomepageCopy(Request $request): array
    {
        $fields = [
            'home_hero_title' => ['Titre noir', 120],
            'home_hero_highlight' => ['Texte rouge', 160],
            'home_hero_description' => ['Paragraphe', 400],
        ];
        $values = [];

        foreach ($fields as $field => [$label, $maxLength]) {
            $value = trim((string) $request->request->get($field));

            if ($value === '') {
                throw new \InvalidArgumentException(sprintf('Le champ « %s » est obligatoire.', $label));
            }

            if (mb_strlen($value) > $maxLength) {
                throw new \InvalidArgumentException(sprintf('Le champ « %s » ne doit pas dépasser %d caractères.', $label, $maxLength));
            }

            $values[] = $value;
        }

        return $values;
    }

    /** @param array<string, mixed> $legalProfile */
    private function validateLegalUrls(array $legalProfile): void
    {
        foreach (['site_url', 'google_business_url', 'facebook_url', 'instagram_url', 'tiktok_url'] as $field) {
            $value = trim((string) ($legalProfile[$field] ?? ''));

            if ($value !== '' && (filter_var($value, FILTER_VALIDATE_URL) === false || !in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true))) {
                throw new \InvalidArgumentException('Les adresses du site, de Google Business et des réseaux sociaux doivent commencer par http:// ou https://.');
            }
        }

        foreach (['business_email', 'privacy_email'] as $field) {
            $value = trim((string) ($legalProfile[$field] ?? ''));

            if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                throw new \InvalidArgumentException('Indiquez des adresses e-mail valides dans les informations légales.');
            }
        }
    }
}
