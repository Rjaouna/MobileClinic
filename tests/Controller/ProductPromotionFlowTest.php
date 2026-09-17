<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Product;
use App\Entity\ProductReservation;
use App\Entity\User;
use App\Repository\ProductRepository;
use App\Repository\ProductReservationRepository;
use App\Service\LoyaltyManager;
use App\Service\ProductReservationManager;
use App\Tests\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProductPromotionFlowTest extends WebTestCase
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
    public function adminCanCreatePromotionWithCustomAttribute(): void
    {
        $admin = $this->user('product-admin@symaclinic.fr', '+33605000999', ['ROLE_ADMIN']);
        $this->entityManager->flush();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/promotions');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('button.dashboard-menu-button[aria-controls="admin-product-menu"]');
        self::assertStringContainsString('Menu promotions', $this->responseContent());
        self::assertSelectorExists('.workspace-navigation__link[href="/admin/reservations-boutique"]');
        self::assertSelectorNotExists('#product-reservation-title');

        $token = $crawler->filter('form[action="/admin/promotions/articles"] input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/admin/promotions/articles', [
            '_token' => $token,
            'name' => 'Coque iPhone MagSafe',
            'normal_price' => '29,90',
            'promotional_price' => '19,90',
            'description' => 'Coque renforcée à récupérer en magasin.',
            'custom_label' => ['Couleur'],
            'custom_value' => ['Transparent'],
            'is_active' => '1',
        ]);

        self::assertResponseRedirects('/admin/promotions');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Coque iPhone MagSafe', $this->responseContent());
        self::assertStringContainsString('1 info', $this->responseContent());

        $product = static::getContainer()->get(ProductRepository::class)->findOneBy(['name' => 'Coque iPhone MagSafe']);
        self::assertInstanceOf(Product::class, $product);
        self::assertSame([['label' => 'Couleur', 'value' => 'Transparent']], $product->getCustomAttributes());
    }

    #[Test]
    public function adminCanDeleteUnreservedPromotion(): void
    {
        $admin = $this->user('delete-product-admin@symaclinic.fr', '+33605000995', ['ROLE_ADMIN']);
        $product = $this->product('Article suppression test', 1500);
        $this->entityManager->flush();
        $productId = $product->getId();
        self::assertIsInt($productId);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/promotions');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists(sprintf('button[data-modal-open="product-delete-%d"]', $productId));
        self::assertSelectorExists(sprintf('dialog#product-delete-%d', $productId));

        $token = $crawler->filter(sprintf('form[action="/admin/promotions/articles/%d/supprimer"] input[name="_token"]', $productId))->first()->attr('value');
        $this->client->request('POST', sprintf('/admin/promotions/articles/%d/supprimer', $productId), [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/admin/promotions');
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Product::class, $productId));

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Article suppression test', $this->responseContent());
    }

    #[Test]
    public function adminCannotDeletePromotionWithActiveReservation(): void
    {
        $admin = $this->user('blocked-delete-admin@symaclinic.fr', '+33605000994', ['ROLE_ADMIN']);
        $customer = $this->user('blocked-delete-client@symaclinic.fr', '+33605000004');
        $product = $this->product('Article réservé non supprimable', 2100);
        $this->entityManager->flush();
        $productId = $product->getId();
        self::assertIsInt($productId);

        static::getContainer()
            ->get(ProductReservationManager::class)
            ->createReservation($customer, [$productId], false);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/promotions');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists(sprintf('dialog#product-delete-%d button[disabled]', $productId));

        $token = $crawler->filter(sprintf('form[action="/admin/promotions/articles/%d/supprimer"] input[name="_token"]', $productId))->first()->attr('value');
        $this->client->request('POST', sprintf('/admin/promotions/articles/%d/supprimer', $productId), [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/admin/promotions');
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('réservation active', $this->responseContent());

        $this->entityManager->clear();
        self::assertInstanceOf(Product::class, $this->entityManager->find(Product::class, $productId));
    }

    #[Test]
    public function customerCanReservePromotionWithLoyaltyAndCancelWithRefund(): void
    {
        $admin = $this->user('reserve-admin@symaclinic.fr', '+33605000998', ['ROLE_ADMIN']);
        $customer = $this->user('reserve-client@symaclinic.fr', '+33605000001');
        $product = $this->product('Protection écran premium', 1200);
        $this->entityManager->flush();
        $this->loyaltyManager->manualCredit($customer, 500, 'Solde test panier', $admin);

        $crawler = $this->client->request('GET', '/promotions');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('dialog#cart-modal');
        self::assertStringContainsString('Protection écran premium', $this->responseContent());
        self::assertStringContainsString('Se connecter', $this->responseContent());
        self::assertSelectorNotExists(sprintf('form[action="/promotions/panier/%d/ajouter"]', $product->getId()));

        $this->client->loginUser($customer);
        $crawler = $this->client->request('GET', '/promotions');
        self::assertResponseIsSuccessful();
        $addToken = $crawler->filter(sprintf('form[action="/promotions/panier/%d/ajouter"] input[name="_token"]', $product->getId()))->first()->attr('value');
        $this->client->request('POST', sprintf('/promotions/panier/%d/ajouter', $product->getId()), [
            '_token' => $addToken,
        ]);
        self::assertResponseRedirects('/promotions?modal=cart-modal');

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('dialog#cart-modal[open]');
        self::assertStringContainsString('Carte utilisée', $this->responseContent());
        self::assertStringContainsString('5 €', $this->responseContent());

        $reserveToken = $crawler->filter('form[action="/promotions/reserver"] input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/promotions/reserver', [
            '_token' => $reserveToken,
            'use_loyalty' => '1',
        ]);

        self::assertResponseRedirects('/espace-client/reservations-boutique');
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-user-live-refresh]');
        self::assertSelectorTextContains('.data-table--customer-reservations thead th:first-child', 'Articles');
        self::assertSelectorNotExists('.data-table--customer-reservations td[data-label="Réservation"]');
        self::assertSelectorExists('[data-reservation-countdown][data-alert-seconds="7200"]');
        self::assertSelectorExists('.reservation-countdown__contact[href="tel:0320507103"]');
        self::assertSelectorTextContains('.reservation-deadline-cell', 'Expire le');
        self::assertSelectorTextContains('.reservation-deadline-cell', 'Appeler : 03 20 50 71 03');
        self::assertStringContainsString('03 20 50 71 03', $this->responseContent());

        $this->client->request('GET', '/espace-client');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#user-dashboard-store-reservations [data-reservation-countdown]');
        self::assertSelectorTextContains('#user-dashboard-store-reservations', 'Protection écran premium');
        self::assertSelectorTextContains('#user-dashboard-store-reservations', 'Prévenir le magasin : 03 20 50 71 03');

        $reservation = $this->singleReservation();
        self::assertSame(ProductReservation::STATUS_RESERVED, $reservation->getStatus());
        self::assertSame(500, $reservation->getLoyaltyUsedCents());
        self::assertSame(700, $reservation->getPayableCents());
        self::assertSame(0, $this->loyaltyManager->buildAccountView($customer)['available_balance_cents']);

        $cancelToken = $crawler->filter(sprintf('form[action="/espace-client/reservations-boutique/%d/annuler"] input[name="_token"]', $reservation->getId()))->first()->attr('value');
        $this->client->request('POST', sprintf('/espace-client/reservations-boutique/%d/annuler', $reservation->getId()), [
            '_token' => $cancelToken,
        ]);

        self::assertResponseRedirects('/espace-client/reservations-boutique');
        $this->entityManager->clear();
        $customer = $this->entityManager->find(User::class, $customer->getId());
        self::assertInstanceOf(User::class, $customer);
        self::assertSame(500, $this->loyaltyManager->buildAccountView($customer)['available_balance_cents']);
        self::assertSame(ProductReservation::STATUS_CANCELLED_BY_CUSTOMER, $this->reservation($reservation)->getStatus());
    }

    #[Test]
    public function anonymousVisitorCannotAddPromotionToCart(): void
    {
        $product = $this->product('Chargeur USB-C promo', 990);
        $this->entityManager->flush();

        $this->client->request('GET', '/promotions');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Se connecter', $this->responseContent());
        self::assertSelectorNotExists(sprintf('form[action="/promotions/panier/%d/ajouter"]', $product->getId()));

        $this->client->request('POST', sprintf('/promotions/panier/%d/ajouter', $product->getId()));

        self::assertResponseRedirects('/promotions?modal=login-modal');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Connectez-vous ou créez un compte pour réserver cet article.', $this->responseContent());
    }

    #[Test]
    public function publicNavigationStaysConsistentOnPromotionsPages(): void
    {
        $product = $this->product('Navigation promo test', 990);
        $this->entityManager->flush();
        $expectedNavigation = ['Réparation', 'Fidélité', 'Actualités', 'Recrutement', 'Contact', 'Promotions'];

        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Mobile Clinic', $this->responseContent());
        self::assertStringNotContainsString('SymClinic', $this->responseContent());
        self::assertSelectorExists('.brand img[alt="Mobile Clinic"], .brand__name');
        self::assertSame($expectedNavigation, $crawler->filter('.site-nav a')->each(static fn ($node): string => trim($node->text())));
        self::assertSelectorExists('.site-nav__link--highlight');
        self::assertSelectorExists('.hero--approved[style*="/images/home-hero-technician-v2.png"]');
        self::assertSelectorNotExists('.hero .appointment-card');
        self::assertSelectorTextContains('.hero__intro', 'Un service rapide, clair et professionnel.');
        self::assertSelectorExists('.site-footer__surface');
        self::assertSelectorExists('.site-footer__decoration[aria-hidden="true"]');
        self::assertSelectorCount(3, '.site-footer__service');
        self::assertSelectorTextContains('.site-footer__links', 'Liens utiles');
        self::assertSelectorTextContains('.site-footer__social-card', 'Suivez-nous');
        self::assertSelectorExists('.site-footer__call-card');
        self::assertSelectorTextContains('.site-footer__call-card', '03 20 50 71 03');
        self::assertSelectorTextContains('.site-footer__call-card', '18 Rue du Sec Arembault, 59800 Lille');
        self::assertSelectorTextContains('.site-footer__call-card', 'Lun. - jeu. 09:30–20:00');
        self::assertSelectorTextContains('.site-footer__call-card', 'Ven. - sam. 09:30–21:00');
        self::assertSelectorTextContains('.site-footer__call-card', 'Dim. 13:30–19:00');
        self::assertSelectorExists('.site-footer__address[href="https://share.google/mUlac1hXzMCLRVTds"][target="_blank"]');
        self::assertSelectorTextContains('.site-footer__contact-actions', 'Itinéraire');
        self::assertStringContainsString('Prendre rendez-vous', $this->responseContent());

        $crawler = $this->client->request('GET', '/promotions');
        self::assertResponseIsSuccessful();
        self::assertSame($expectedNavigation, $crawler->filter('.site-nav a')->each(static fn ($node): string => trim($node->text())));
        self::assertSelectorExists('.site-nav__link--highlight.is-active');
        self::assertStringContainsString('Voir le panier', $this->responseContent());

        $crawler = $this->client->request('GET', sprintf('/promotions/%d', $product->getId()));
        self::assertResponseIsSuccessful();
        self::assertSame($expectedNavigation, $crawler->filter('.site-nav a')->each(static fn ($node): string => trim($node->text())));
        self::assertSelectorExists('.site-nav__link--highlight.is-active');
        self::assertStringContainsString('Voir le panier', $this->responseContent());
    }

    #[Test]
    public function adminCanConfirmReservationAndArticleKeepsReservedBadge(): void
    {
        $admin = $this->user('confirm-product-admin@symaclinic.fr', '+33605000997', ['ROLE_ADMIN']);
        $customer = $this->user('confirm-product-client@symaclinic.fr', '+33605000002');
        $product = $this->product('Samsung Galaxy reconditionné', 15900);
        $this->entityManager->flush();

        $reservation = static::getContainer()
            ->get(ProductReservationManager::class)
            ->createReservation($customer, [(int) $product->getId()], false);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/reservations-boutique');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.workspace-navigation__link.is-active[href="/admin/reservations-boutique"]');
        self::assertSelectorExists('[data-admin-store-live-refresh][data-admin-store-live-refresh-interval="10000"]');
        self::assertSelectorExists('input[name="reservation_search"][type="search"]');
        self::assertSelectorExists('select[name="reservation_urgency"] option[value="soon"]');
        self::assertSelectorExists('[data-reservation-countdown][data-alert-seconds="7200"]');
        self::assertSelectorExists('.table-contact-link[href="tel:+33605000002"]');
        self::assertStringContainsString('Clients à appeler', $this->responseContent());

        $token = $crawler->filter(sprintf('form[action="/admin/promotions/reservations/%d/valider"] input[name="_token"]', $reservation->getId()))->first()->attr('value');
        $this->client->request('POST', sprintf('/admin/promotions/reservations/%d/valider', $reservation->getId()), [
            '_token' => $token,
            'admin_note' => 'Retiré en magasin',
        ]);

        self::assertResponseRedirects('/admin/reservations-boutique?reservation_status=reserved');
        self::assertSame(ProductReservation::STATUS_CONFIRMED, $this->reservation($reservation)->getStatus());

        $this->client->request('GET', '/promotions');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Déjà réservé', $this->responseContent());
        self::assertStringContainsString('Samsung Galaxy reconditionné', $this->responseContent());
    }

    #[Test]
    public function adminCanReleaseConfirmedReservationAndRefundLoyalty(): void
    {
        $admin = $this->user('release-product-admin@symaclinic.fr', '+33605000992', ['ROLE_ADMIN']);
        $customer = $this->user('release-product-client@symaclinic.fr', '+33605000006');
        $product = $this->product('Chargeur remis en vente', 3490);
        $this->entityManager->flush();
        $this->loyaltyManager->manualCredit($customer, 500, 'Solde test remise en vente', $admin);

        $manager = static::getContainer()->get(ProductReservationManager::class);
        $reservation = $manager->createReservation($customer, [(int) $product->getId()], true);
        $manager->confirm($reservation, $admin, 'Garde prolongée par le magasin');

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/reservations-boutique');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.data-table__actions', 'Remettre en vente');
        $releaseButton = $crawler->filter(sprintf('button[formaction="/admin/promotions/reservations/%d/annuler"]', $reservation->getId()))->first();
        $token = $releaseButton->ancestors()->filter('form')->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', sprintf('/admin/promotions/reservations/%d/annuler', $reservation->getId()), [
            '_token' => $token,
            'admin_note' => 'Le client ne viendra finalement pas.',
        ]);

        self::assertResponseRedirects('/admin/reservations-boutique');
        $this->entityManager->clear();

        $reservation = $this->reservation($reservation);
        $product = $this->entityManager->find(Product::class, $product->getId());
        $customer = $this->entityManager->find(User::class, $customer->getId());
        self::assertInstanceOf(Product::class, $product);
        self::assertInstanceOf(User::class, $customer);
        self::assertSame(ProductReservation::STATUS_CANCELLED_BY_ADMIN, $reservation->getStatus());
        self::assertFalse($product->isSold());
        self::assertTrue($product->isActive());
        self::assertFalse(static::getContainer()->get(ProductRepository::class)->isBlocked($product));
        self::assertSame(500, $this->loyaltyManager->buildAccountView($customer)['available_balance_cents']);
    }

    #[Test]
    public function adminLiveRefreshExpiresOverdueStoreReservation(): void
    {
        $admin = $this->user('refresh-product-admin@symaclinic.fr', '+33605000993', ['ROLE_ADMIN']);
        $customer = $this->user('refresh-product-client@symaclinic.fr', '+33605000005');
        $product = $this->product('Chargeur à expiration contrôlée', 2490);
        $this->entityManager->flush();

        $reservation = static::getContainer()
            ->get(ProductReservationManager::class)
            ->createReservation($customer, [(int) $product->getId()], false)
            ->setExpiresAt(new \DateTimeImmutable('-1 minute'));
        $this->entityManager->flush();

        $this->client->loginUser($admin);
        $this->client->request('GET', '/api/admin/promotions/reservations/actualisation');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        $payload = json_decode($this->responseContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $payload['expired']);
        self::assertSame('#admin-product-reservation-table', $payload['fragments'][0]['selector']);
        self::assertStringContainsString('Expiré', $payload['fragments'][0]['html']);

        $this->entityManager->clear();
        self::assertSame(ProductReservation::STATUS_EXPIRED, $this->reservation($reservation)->getStatus());
    }

    #[Test]
    public function adminCanMarkReservationAsWithdrawnAndProductIsSold(): void
    {
        $admin = $this->user('withdraw-product-admin@symaclinic.fr', '+33605000996', ['ROLE_ADMIN']);
        $customer = $this->user('withdraw-product-client@symaclinic.fr', '+33605000003');
        $product = $this->product('Coque retirée test', 1900);
        $this->entityManager->flush();
        $this->loyaltyManager->manualCredit($customer, 700, 'Solde test retrait', $admin);

        $reservation = static::getContainer()
            ->get(ProductReservationManager::class)
            ->createReservation($customer, [(int) $product->getId()], true);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/reservations-boutique');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists(sprintf('form[action="/admin/promotions/reservations/%d/retirer"]', $reservation->getId()));

        $token = $crawler->filter(sprintf('form[action="/admin/promotions/reservations/%d/retirer"] input[name="_token"]', $reservation->getId()))->first()->attr('value');
        $this->client->request('POST', sprintf('/admin/promotions/reservations/%d/retirer', $reservation->getId()), [
            '_token' => $token,
            'admin_note' => 'Client passé en magasin',
        ]);

        self::assertResponseRedirects('/admin/reservations-boutique');
        $this->entityManager->clear();

        $reservation = $this->reservation($reservation);
        $product = $this->entityManager->find(Product::class, $product->getId());
        $customer = $this->entityManager->find(User::class, $customer->getId());
        self::assertInstanceOf(Product::class, $product);
        self::assertInstanceOf(User::class, $customer);
        self::assertSame(ProductReservation::STATUS_SOLD, $reservation->getStatus());
        self::assertTrue($product->isSold());
        self::assertFalse($product->isActive());
        self::assertSame(0, $this->loyaltyManager->buildAccountView($customer)['available_balance_cents']);

        $this->client->request('GET', '/promotions');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Coque retirée test', $this->responseContent());

        $this->client->request('GET', '/admin/promotions?filter=sold');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Coque retirée test', $this->responseContent());
        self::assertStringContainsString('Vendu', $this->responseContent());

        $this->client->request('GET', '/admin/reservations-boutique?reservation_status=sold');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Retiré / vendu', $this->responseContent());
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

    private function product(string $name, int $promotionalPriceCents): Product
    {
        $product = (new Product())
            ->setName($name)
            ->setDescription('Article promotionnel en retrait magasin.')
            ->setNormalPriceCents($promotionalPriceCents + 500)
            ->setPromotionalPriceCents($promotionalPriceCents)
            ->setCustomAttributes([
                ['label' => 'Garantie', 'value' => '6 mois'],
            ]);

        $this->entityManager->persist($product);

        return $product;
    }

    private function singleReservation(): ProductReservation
    {
        $reservations = static::getContainer()->get(ProductReservationRepository::class)->findAll();
        self::assertCount(1, $reservations);

        return $reservations[0];
    }

    private function reservation(ProductReservation $reservation): ProductReservation
    {
        $reloaded = static::getContainer()->get(ProductReservationRepository::class)->find($reservation->getId());
        self::assertInstanceOf(ProductReservation::class, $reloaded);

        return $reloaded;
    }

    private function responseContent(): string
    {
        return $this->client->getResponse()->getContent() ?: '';
    }
}
