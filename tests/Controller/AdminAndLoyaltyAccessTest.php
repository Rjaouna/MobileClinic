<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Appointment;
use App\Entity\AppointmentAvailability;
use App\Entity\User;
use App\Service\LoyaltyManager;
use App\Service\LoyaltyQrCodeService;
use App\Tests\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminAndLoyaltyAccessTest extends WebTestCase
{
    use DatabaseResetTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private LoyaltyManager $loyaltyManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->entityManager = $this->resetDoctrineSchema();
        $this->loyaltyManager = static::getContainer()->get(LoyaltyManager::class);
    }

    #[Test]
    public function adminPagesRequireAdminAccess(): void
    {
        $this->client->request('GET', '/admin/fidelite');
        self::assertResponseRedirects('/connexion');

        $customer = $this->user('simple-user@symaclinic.fr', '+33602000001');
        $this->entityManager->flush();

        $this->client->loginUser($customer);
        $this->client->request('GET', '/admin/fidelite');
        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function adminDashboardUsesQuickAccessFirstWithoutHeaderMenu(): void
    {
        $admin = $this->user('dashboard-layout-admin@symaclinic.fr', '+33602000992', ['ROLE_ADMIN']);
        $customer = $this->user('dashboard-client@symaclinic.fr', '+33602000912');
        $secondCustomer = $this->user('dashboard-client-two@symaclinic.fr', '+33602000913');
        $this->appointment($customer, 'iPhone', 'Batterie faible');
        $this->appointment($secondCustomer, 'Samsung Galaxy', 'Écran cassé')
            ->setStatus(Appointment::STATUS_CONFIRMED);
        $this->appointment($secondCustomer, 'Xiaomi', 'Diagnostic complet')
            ->setStatus(Appointment::STATUS_COMPLETED);
        $this->entityManager->flush();

        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#admin-dashboard-menu');
        self::assertSelectorTextContains('#admin-access-title', 'Pilotage');
        self::assertSelectorExists('#admin-notification-center[hidden]');
        self::assertSelectorExists('.admin-panel--priority .admin-access-card[href="/admin/rendez-vous"]');
        self::assertSelectorTextContains('.admin-panel--priority .admin-access-card[href="/admin/rendez-vous"] .nav-badge', '2');
        self::assertSelectorTextContains('.admin-panel--priority .admin-access-card[href="/admin/clients"] .nav-badge', '2');
        self::assertSelectorExists('.admin-panel--priority .admin-access-card[href="/admin/reservations-boutique"]');
        self::assertSelectorExists('.admin-panel--priority .btn[href="/"]');
        self::assertSelectorExists('.admin-panel--priority form.dashboard-logout-form[action="/deconnexion"][method="post"]');
        self::assertLessThan(
            strpos($this->responseContent(), 'data-admin-notifications'),
            strpos($this->responseContent(), 'admin-panel--priority')
        );
    }

    #[Test]
    public function adminSectionMenusStayLocalWithDashboardReturn(): void
    {
        $admin = $this->user('local-menu-admin@symaclinic.fr', '+33602000990', ['ROLE_ADMIN']);
        $this->entityManager->flush();

        $this->client->loginUser($admin);

        $cases = [
            ['/admin/rendez-vous', 'admin-appointment-menu', ['/admin/promotions', '/admin/clients', '/admin/fidelite']],
            ['/admin/promotions', 'admin-product-menu', ['/admin/rendez-vous', '/admin/clients', '/admin/fidelite', '/admin/parametres-generaux']],
            ['/admin/reservations-boutique', 'admin-product-reservation-menu', ['/admin/rendez-vous', '/admin/promotions', '/admin/clients', '/admin/fidelite', '/admin/parametres-generaux']],
            ['/admin/clients', 'admin-customer-menu', ['/admin/rendez-vous', '/admin/promotions', '/admin/fidelite', '/admin/parametres-generaux']],
            ['/admin/fidelite', 'admin-loyalty-menu', ['/admin/rendez-vous', '/admin/promotions', '/admin/clients', '/admin/parametres-generaux']],
            ['/admin/parametres-generaux', 'admin-settings-menu', ['/admin/rendez-vous', '/admin/promotions', '/admin/clients', '/admin/fidelite']],
        ];

        foreach ($cases as [$path, $menuId, $forbiddenPrefixes]) {
            $this->client->request('GET', $path);

            self::assertResponseIsSuccessful();
            self::assertSelectorExists(sprintf('dialog#%s a[href="/admin"]', $menuId));

            foreach ($forbiddenPrefixes as $prefix) {
                self::assertSelectorNotExists(sprintf('dialog#%s a[href^="%s"]', $menuId, $prefix));
            }
        }
    }

    #[Test]
    public function userSectionMenusStayLocalWithDashboardReturn(): void
    {
        $customer = $this->user('local-menu-user@symaclinic.fr', '+33602000989');
        $appointment = $this->appointment($customer, 'iPhone', 'Écran cassé');
        $this->entityManager->flush();

        $appointmentId = $appointment->getId();
        self::assertIsInt($appointmentId);

        $this->client->loginUser($customer);

        $cases = [
            ['/espace-client/rendez-vous', 'user-reservation-menu', ['/promotions', '/espace-client/reservations-boutique', '/espace-client/fidelite', '/espace-client/profil']],
            [sprintf('/espace-client/rendez-vous/%d', $appointmentId), 'user-reservation-show-menu', ['/promotions', '/espace-client/reservations-boutique', '/espace-client/fidelite', '/espace-client/profil']],
            ['/espace-client/reservations-boutique', 'user-store-menu', ['/espace-client/rendez-vous', '/espace-client/fidelite', '/espace-client/profil']],
            ['/espace-client/fidelite', 'user-loyalty-menu', ['/promotions', '/espace-client/rendez-vous', '/espace-client/reservations-boutique', '/espace-client/profil']],
            ['/espace-client/profil', 'user-profile-menu', ['/promotions', '/espace-client/rendez-vous', '/espace-client/reservations-boutique', '/espace-client/fidelite']],
        ];

        foreach ($cases as [$path, $menuId, $forbiddenPrefixes]) {
            $this->client->request('GET', $path);

            self::assertResponseIsSuccessful();
            self::assertSelectorExists(sprintf('dialog#%s a[href="/espace-client"]', $menuId));

            foreach ($forbiddenPrefixes as $prefix) {
                self::assertSelectorNotExists(sprintf('dialog#%s a[href^="%s"]', $menuId, $prefix));
            }
        }
    }

    #[Test]
    public function internalMenusDoNotRepeatTheCurrentMainBlock(): void
    {
        $admin = $this->user('smart-menu-admin@symaclinic.fr', '+33602000988', ['ROLE_ADMIN']);
        $customer = $this->user('smart-menu-user@symaclinic.fr', '+33602000987');
        $this->entityManager->flush();

        $this->client->loginUser($admin);

        $this->client->request('GET', '/admin/promotions');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('dialog#admin-product-menu a[href="/admin/promotions#product-filter-title"]');
        self::assertSelectorNotExists('#product-reservation-title');

        $this->client->request('GET', '/admin/reservations-boutique');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#product-reservation-title');
        self::assertSelectorNotExists('#product-filter-title');
        self::assertSelectorExists('.workspace-navigation__link.is-active[href="/admin/reservations-boutique"]');

        $this->client->request('GET', '/admin/rendez-vous');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/admin/rendez-vous#admin-notification-center"]');
        self::assertSelectorNotExists('a[href="/admin/rendez-vous#appointment-list-title"]');

        $this->client->request('GET', '/admin/clients');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/admin/clients#customer-filter-title"]');
        self::assertSelectorNotExists('a[href="/admin/clients#customer-list-title"]');

        $this->client->request('GET', '/admin/fidelite');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('dialog#admin-loyalty-menu a[href="/admin/fidelite#loyalty-filter-title"]');
        self::assertSelectorNotExists('a[href="/admin/fidelite#loyalty-list-title"]');

        $this->client->loginUser($customer);

        $this->client->request('GET', '/espace-client/rendez-vous');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('dialog#user-reservation-menu a[href="#user-reservation-list"]');
        self::assertSelectorNotExists('dialog#user-reservation-menu a[href="#user-reservation-loyalty"]');

        $this->client->request('GET', '/espace-client/reservations-boutique');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('dialog#user-store-menu a[href="#user-store-reservation-list"]');
        self::assertSelectorNotExists('dialog#user-store-menu a[href="#user-store-loyalty"]');

        $this->client->request('GET', '/espace-client/profil');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('dialog#user-profile-menu a[href="#user-profile-summary"]');
    }

    #[Test]
    public function clientSectionsShowTheirPrimaryContentBeforeLoyalty(): void
    {
        $customer = $this->user('content-order-user@symaclinic.fr', '+33602000986');
        $appointment = $this->appointment($customer, 'iPhone', 'Batterie faible');
        $this->entityManager->flush();

        $appointmentId = $appointment->getId();
        self::assertIsInt($appointmentId);

        $this->client->loginUser($customer);

        $cases = [
            ['/espace-client/rendez-vous', 'user-reservation-list', 'user-reservation-loyalty'],
            ['/espace-client/reservations-boutique', 'user-store-reservation-list', 'user-store-loyalty'],
            [sprintf('/espace-client/rendez-vous/%d', $appointmentId), 'user-reservation-detail-'.$appointmentId, 'user-reservation-show-loyalty'],
        ];

        foreach ($cases as [$path, $primaryId, $loyaltyId]) {
            $this->client->request('GET', $path);

            self::assertResponseIsSuccessful();
            $html = $this->responseContent();
            $primaryPosition = strpos($html, sprintf('id="%s"', $primaryId));
            $loyaltyPosition = strpos($html, sprintf('id="%s"', $loyaltyId));

            self::assertNotFalse($primaryPosition);
            self::assertNotFalse($loyaltyPosition);
            self::assertTrue($primaryPosition < $loyaltyPosition, sprintf('Le contenu principal de %s doit précéder la fidélité.', $path));
        }
    }

    #[Test]
    public function adminCanSeeLoyaltyCardsAndClientCannotEditThem(): void
    {
        $admin = $this->user('admin@symaclinic.fr', '+33602000999', ['ROLE_ADMIN']);
        $customer = $this->user('client-loyalty@symaclinic.fr', '+33602000002');
        $this->entityManager->flush();

        $this->loyaltyManager->manualCredit($customer, 200, 'Test crédit admin', $admin);

        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/fidelite');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('button.dashboard-menu-button[aria-controls="admin-loyalty-menu"]');
        self::assertSelectorExists('.dashboard-site-button[href="/"]');
        self::assertSelectorExists('dialog#admin-loyalty-menu');
        self::assertStringContainsString('Voir le site', $this->responseContent());
        self::assertStringContainsString('client-loyalty@symaclinic.fr', $this->responseContent());
        self::assertStringContainsString('2 €', $this->responseContent());

        $this->client->loginUser($customer);
        $this->client->request('GET', '/espace-client/fidelite');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('button.dashboard-menu-button[aria-controls="user-loyalty-menu"]');
        self::assertSelectorNotExists('.dashboard-site-button[href="/"]');
        self::assertSelectorExists('dialog#user-loyalty-menu');
        self::assertSelectorTextContains('h1', 'Ma fidélité');
        self::assertStringContainsString('Disponible', $this->responseContent());
        self::assertStringNotContainsString('Débiter la cagnotte', $this->responseContent());

        $this->client->request('GET', '/espace-client/rendez-vous');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Ma carte de fidélité', $this->responseContent());
    }

    #[Test]
    public function loyaltyQrCodeIdentifiesTheCustomerOnlyForAnAdminAndExpires(): void
    {
        $admin = $this->user('qr-admin@symaclinic.fr', '+33602000881', ['ROLE_ADMIN']);
        $customer = $this->user('qr-client@symaclinic.fr', '+33602000882');
        $this->entityManager->flush();

        $qrCodeService = static::getContainer()->get(LoyaltyQrCodeService::class);
        $qrCode = $qrCodeService->createFor($customer);

        self::assertStringStartsWith('data:image/svg+xml;base64,', $qrCode['data_uri']);
        self::assertStringContainsString('/admin/fidelite/scan/', $qrCode['scan_url']);
        self::assertStringContainsString('_expiration=', $qrCode['scan_url']);
        self::assertStringContainsString('_hash=', $qrCode['scan_url']);

        $this->client->loginUser($customer);
        $this->client->request('GET', '/espace-client/fidelite/qr-code');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.workspace-navigation__link.is-active[href="/espace-client/fidelite/qr-code"]');
        self::assertSelectorExists('[data-loyalty-qr][data-validity-seconds="300"]');
        self::assertSelectorExists('.loyalty-qr-card__visual img[src^="data:image/svg+xml;base64,"]');

        $this->client->request('GET', $qrCode['scan_url']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->loginUser($admin);
        $this->client->request('GET', $qrCode['scan_url']);
        self::assertResponseRedirects(sprintf('/admin/fidelite/%d', $customer->getId()));
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $customer->getDisplayName());
        self::assertStringContainsString('Client identifié', $this->responseContent());

        $tamperedUrl = preg_replace('/(_hash=)[^&]+/', '$1signature-invalide', $qrCode['scan_url']);
        self::assertIsString($tamperedUrl);
        $this->client->request('GET', $tamperedUrl);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSelectorTextContains('h1', 'QR code invalide');

        $expiredQrCode = $qrCodeService->createFor($customer, new \DateTimeImmutable('-10 minutes'));
        $this->client->request('GET', $expiredQrCode['scan_url']);
        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
        self::assertSelectorTextContains('h1', 'QR code expiré');
    }

    #[Test]
    public function adminLoyaltyWriteActionsRequireCsrfToken(): void
    {
        $admin = $this->user('csrf-admin@symaclinic.fr', '+33602000998', ['ROLE_ADMIN']);
        $customer = $this->user('csrf-client@symaclinic.fr', '+33602000003');
        $this->entityManager->flush();

        $this->loyaltyManager->manualCredit($customer, 300, 'Solde initial', $admin);
        $this->client->loginUser($admin);
        $this->client->request('POST', sprintf('/api/admin/fidelite/clients/%d/utiliser', $customer->getId()), [
            'amount' => '1',
            'reason_type' => 'Réparation',
            'reason' => 'Sans jeton',
        ]);

        self::assertResponseStatusCodeSame(403);
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true);
        self::assertFalse($payload['success'] ?? true);
        self::assertSame(300, $this->loyaltyManager->buildAccountView($customer)['available_balance_cents']);
    }

    #[Test]
    public function partialLoyaltyRedemptionImmediatelyUpdatesAdminAndCustomerBalances(): void
    {
        $admin = $this->user('partial-redeem-admin@symaclinic.fr', '+33602000991', ['ROLE_ADMIN']);
        $customer = $this->user('partial-redeem-client@symaclinic.fr', '+33602000031');
        $this->entityManager->flush();
        $this->loyaltyManager->manualCredit($customer, 200, 'Solde de départ', $admin);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', sprintf('/admin/fidelite/%d', $customer->getId()));
        $token = $crawler->filter(sprintf(
            'form[action="/api/admin/fidelite/clients/%d/utiliser"] input[name="_token"]',
            $customer->getId(),
        ))->attr('value');

        $this->client->request('POST', sprintf('/api/admin/fidelite/clients/%d/utiliser', $customer->getId()), [
            '_token' => $token,
            'amount' => '1',
            'reason_type' => 'Réparation',
            'reason' => 'Utilisation partielle',
        ], [], [
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true);
        self::assertTrue($payload['success'] ?? false);
        self::assertSame(100, $payload['loyalty_balance']['available_balance_cents'] ?? null);
        self::assertSame('1 €', $payload['loyalty_balance']['available_balance_label'] ?? null);

        $fragments = [];
        foreach ($payload['fragments'] ?? [] as $fragment) {
            if (isset($fragment['selector'], $fragment['html'])) {
                $fragments[$fragment['selector']] = $fragment['html'];
            }
        }

        $detail = new Crawler($fragments['#loyalty-detail-card'] ?? '');
        self::assertSame('1 €', trim($detail->filter('[data-loyalty-available-balance]')->text()));

        $this->client->loginUser($customer);
        $this->client->request('GET', '/api/espace-client/actualisation');
        self::assertResponseIsSuccessful();
        $customerPayload = json_decode($this->client->getResponse()->getContent() ?: '{}', true);
        $loyaltyCardHtml = '';

        foreach ($customerPayload['fragments'] ?? [] as $fragment) {
            if (($fragment['selector'] ?? null) === '#user-loyalty-card') {
                $loyaltyCardHtml = (string) ($fragment['html'] ?? '');
            }
        }

        $loyaltyCard = new Crawler($loyaltyCardHtml);
        self::assertSame('1 €', trim($loyaltyCard->filter('.loyalty-amount--available strong')->text()));
    }

    #[Test]
    public function adminCannotCreateTwoCustomersWithSamePhone(): void
    {
        $admin = $this->user('phone-admin@symaclinic.fr', '+33602000997', ['ROLE_ADMIN']);
        $this->user('phone-owner@symaclinic.fr', '+33602000004');
        $this->entityManager->flush();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/clients');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form[action="/api/admin/clients"] input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/api/admin/clients', [
            '_token' => $token,
            'email' => 'phone-copy@symaclinic.fr',
            'phone' => '06 02 00 00 04',
            'password' => 'admin-test-pass',
            'is_active' => '1',
        ]);

        self::assertResponseStatusCodeSame(422);
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true);
        self::assertContains('Ce numéro de téléphone est déjà utilisé.', $payload['errors'] ?? []);
    }

    #[Test]
    public function adminCustomerListUsesInstantFiltersAndServerSearch(): void
    {
        $admin = $this->user('search-admin@symaclinic.fr', '+33602000996', ['ROLE_ADMIN']);
        $this->user('alpha-client@symaclinic.fr', '+33602000006');
        $this->user('beta-client@symaclinic.fr', '+33602000007');
        $this->entityManager->flush();

        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/clients?q=alpha');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-instant-filter] input[name="q"]');
        self::assertStringContainsString('alpha-client@symaclinic.fr', $this->responseContent());
        self::assertStringNotContainsString('beta-client@symaclinic.fr', $this->responseContent());
    }

    #[Test]
    public function adminAppointmentListCanSearchCustomersAndRequestsInstantly(): void
    {
        $admin = $this->user('appointment-search-admin@symaclinic.fr', '+33602000995', ['ROLE_ADMIN']);
        $alpha = $this->user('rdv-alpha@symaclinic.fr', '+33602000008');
        $beta = $this->user('rdv-beta@symaclinic.fr', '+33602000009');
        $this->appointment($alpha, 'iPhone', 'Écran cassé');
        $this->appointment($beta, 'Samsung', 'Batterie faible');
        $this->entityManager->flush();

        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/rendez-vous?q=batterie');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-instant-filter] input[name="q"]');
        self::assertStringContainsString('Batterie faible', $this->responseContent());
        self::assertStringContainsString('rdv-beta@symaclinic.fr', $this->responseContent());
        self::assertStringNotContainsString('Écran cassé', $this->responseContent());
        self::assertStringNotContainsString('rdv-alpha@symaclinic.fr', $this->responseContent());
    }

    #[Test]
    public function adminSettingsDayEntryShowsSelectedDayPanel(): void
    {
        $admin = $this->user('settings-day-admin@symaclinic.fr', '+33602000994', ['ROLE_ADMIN']);
        $this->availability(3, '09:00', '12:00');
        $this->entityManager->flush();

        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/parametres-generaux/jours/3');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#jour-3');
        self::assertSelectorTextContains('#selected-day-title', 'Mercredi');
        self::assertStringContainsString('09:00 - 12:00', $this->responseContent());
        self::assertStringContainsString('/admin/parametres-generaux/jours/3#jour-3', $this->responseContent());
    }

    #[Test]
    public function adminCanConfigureStoreReservationDurationAndAlertThreshold(): void
    {
        $admin = $this->user('store-settings-admin@symaclinic.fr', '+33602000993', ['ROLE_ADMIN']);
        $this->entityManager->flush();
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/parametres-generaux');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-store-reservation-settings] input[name="product_reservation_alert_minutes"]');
        $token = $crawler->filter('form[action="/admin/parametres-generaux/boutique"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/parametres-generaux/boutique', [
            '_token' => $token,
            'store_phone' => '03 20 50 71 03',
            'product_reservation_hold_minutes' => '180',
            'product_reservation_alert_minutes' => '45',
        ]);

        self::assertResponseRedirects('/admin/parametres-generaux#store-settings-title');
        $setting = static::getContainer()->get(\App\Service\GeneralSettingManager::class)->getSetting();
        self::assertSame(180, $setting->getProductReservationHoldMinutes());
        self::assertSame(45, $setting->getProductReservationAlertMinutes());
    }

    #[Test]
    public function adminCanConfigureHomepageHeroContentAndPhotoOpacity(): void
    {
        $admin = $this->user('homepage-opacity-admin@symaclinic.fr', '+33602000986', ['ROLE_ADMIN']);
        $this->entityManager->flush();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/parametres-generaux');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-homepage-photo-settings] input[name="home_hero_photo_opacity"][type="range"]');
        self::assertSelectorExists('form[data-homepage-photo-settings] input[name="home_hero_title"]');
        self::assertSelectorExists('form[data-homepage-photo-settings] input[name="home_hero_highlight"]');
        self::assertSelectorExists('form[data-homepage-photo-settings] textarea[name="home_hero_description"]');
        self::assertSelectorNotExists('form[data-homepage-photo-settings] input[name="home_hero_photo_path"]');
        $form = $crawler->filter('form[action="/admin/parametres-generaux/accueil"]')->form([
            'home_hero_photo_opacity' => '45',
            'home_hero_title' => 'Votre mobile,',
            'home_hero_highlight' => 'réparé simplement.',
            'home_hero_description' => 'Choisissez votre réparation et votre créneau en quelques instants.',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/parametres-generaux#homepage-settings-title');

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.hero--with-background[style*="--hero-background-opacity: 45%"]');
        self::assertSelectorExists('.hero--with-background[style*="/images/home-hero-technician-v2.png"]');
        self::assertSelectorTextContains('.hero__title', 'Votre mobile,');
        self::assertSelectorTextContains('.hero__title span', 'réparé simplement.');
        self::assertSelectorTextContains('.hero__intro', 'Choisissez votre réparation et votre créneau en quelques instants.');
    }

    #[Test]
    public function adminCanUploadSiteLogoDisplayedInPublicHeader(): void
    {
        $admin = $this->user('site-logo-admin@symaclinic.fr', '+33602000985', ['ROLE_ADMIN']);
        $this->entityManager->flush();
        $temporaryLogo = tempnam(sys_get_temp_dir(), 'symclinic-logo-');

        self::assertIsString($temporaryLogo);
        file_put_contents($temporaryLogo, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));

        try {
            $this->client->loginUser($admin);
            $crawler = $this->client->request('GET', '/admin/parametres-generaux');

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('form[data-site-logo-settings] input[name="site_logo"][accept*=".png"]');
            $token = $crawler->filter('form[action="/admin/parametres-generaux/logo"] input[name="_token"]')->attr('value');

            $this->client->request('POST', '/admin/parametres-generaux/logo', ['_token' => $token], [
                'site_logo' => new UploadedFile($temporaryLogo, 'symclinic-logo.png', 'image/png', UPLOAD_ERR_OK, true),
            ]);

            self::assertResponseRedirects('/admin/parametres-generaux#site-logo-settings-title');

            $this->entityManager->clear();
            $setting = static::getContainer()->get(\App\Service\GeneralSettingManager::class)->getSetting();
            $logoPath = $setting->getSiteLogoPath();

            self::assertNotNull($logoPath);
            self::assertStringStartsWith('/uploads/site/logo-', $logoPath);

            $this->client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists(sprintf('.site-header img.brand__logo[src="%s"]', $logoPath));
            self::assertSelectorExists(sprintf('.site-footer img.brand__logo[src="%s"]', $logoPath));

            $uploadedLogo = static::getContainer()->getParameter('kernel.project_dir').'/public'.$logoPath;

            if (is_file($uploadedLogo)) {
                unlink($uploadedLogo);
            }
        } finally {
            if (is_file($temporaryLogo)) {
                unlink($temporaryLogo);
            }
        }
    }

    #[Test]
    public function adminCannotCreateDuplicateAvailabilityRange(): void
    {
        $admin = $this->user('duplicate-range-admin@symaclinic.fr', '+33602000993', ['ROLE_ADMIN']);
        $this->availability(3, '09:00', '18:00');
        $this->entityManager->flush();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/parametres-generaux/jours/3');
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('form[action="/admin/rendez-vous/plages"] input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/admin/rendez-vous/plages', [
            '_token' => $token,
            '_redirect_day' => '3',
            '_redirect_settings' => '1',
            'day_of_week' => '3',
            'slot_duration_minutes' => '30',
            'start_time' => '09:00',
            'end_time' => '18:00',
            'is_enabled' => '1',
        ]);

        self::assertResponseRedirects('/admin/parametres-generaux/jours/3#jour-3');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Cette plage horaire existe déjà : 09:00 - 18:00.', $this->responseContent());

        $ranges = $this->entityManager->getRepository(AppointmentAvailability::class)->findBy(['dayOfWeek' => 3]);
        self::assertCount(1, $ranges);
    }

    #[Test]
    public function adminDayVisibilityRedirectsBackToSelectedDayAnchor(): void
    {
        $admin = $this->user('visibility-day-admin@symaclinic.fr', '+33602000991', ['ROLE_ADMIN']);
        $this->availability(3, '09:00', '12:00');
        $this->entityManager->flush();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/parametres-generaux/jours/3');
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('form[action="/admin/rendez-vous/jours/3/basculer"] input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/admin/rendez-vous/jours/3/basculer', [
            '_token' => $token,
            '_redirect_day' => '3',
            '_redirect_settings' => '1',
        ]);

        self::assertResponseRedirects('/admin/parametres-generaux/jours/3#jour-3');
    }

    #[Test]
    public function clientLoyaltyPageShowsPendingAppointmentReward(): void
    {
        $customer = $this->user('pending-client@symaclinic.fr', '+33602000005');
        $appointment = (new Appointment())
            ->setCustomer($customer)
            ->setEmail($customer->getEmail())
            ->setPhone($customer->getPhone())
            ->setDevice('iPhone')
            ->setProblem('Batterie faible')
            ->setScheduledAt(new \DateTimeImmutable('+1 day'))
            ->setDurationMinutes(30);

        $this->entityManager->persist($appointment);
        $this->loyaltyManager->createPendingAppointmentReward($appointment);

        $this->client->loginUser($customer);
        $this->client->request('GET', '/espace-client/fidelite');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('En attente', $this->responseContent());
        self::assertStringContainsString('2 €', $this->responseContent());
    }

    /**
     * @param list<string> $roles
     */
    private function user(string $email, string $phone, array $roles = ['ROLE_USER']): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setPhone($phone)
            ->setRoles($roles)
            ->setIsActive(true);

        $passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($passwordHasher->hashPassword($user, $email));

        $this->entityManager->persist($user);

        return $user;
    }

    private function appointment(User $customer, string $device, string $problem): Appointment
    {
        $appointment = (new Appointment())
            ->setCustomer($customer)
            ->setEmail($customer->getEmail())
            ->setPhone($customer->getPhone())
            ->setDevice($device)
            ->setProblem($problem)
            ->setScheduledAt(new \DateTimeImmutable('+1 day'))
            ->setDurationMinutes(30);

        $this->entityManager->persist($appointment);

        return $appointment;
    }

    private function availability(int $day, string $start, string $end): AppointmentAvailability
    {
        $availability = (new AppointmentAvailability())
            ->setDayOfWeek($day)
            ->setStartTime(new \DateTimeImmutable($start))
            ->setEndTime(new \DateTimeImmutable($end))
            ->setSlotDurationMinutes(30);

        $this->entityManager->persist($availability);

        return $availability;
    }

    private function responseContent(): string
    {
        return $this->client->getResponse()->getContent() ?: '';
    }
}
