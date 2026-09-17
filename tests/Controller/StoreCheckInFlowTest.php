<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CustomerCheckIn;
use App\Entity\User;
use App\Repository\CustomerCheckInRepository;
use App\Service\StoreCheckInManager;
use App\Tests\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class StoreCheckInFlowTest extends WebTestCase
{
    use DatabaseResetTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private StoreCheckInManager $checkInManager;
    private CustomerCheckInRepository $checkInRepository;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->entityManager = $this->resetDoctrineSchema();
        $this->checkInManager = static::getContainer()->get(StoreCheckInManager::class);
        $this->checkInRepository = static::getContainer()->get(CustomerCheckInRepository::class);
    }

    #[Test]
    public function authenticatedCustomerCanCheckInOnceAndAdminCanOpenLoyalty(): void
    {
        $admin = $this->user('check-in-admin@mobileclinic.fr', '+33609000991', ['ROLE_ADMIN']);
        $customer = $this->user('check-in-client@mobileclinic.fr', '+33609000101');
        $this->entityManager->flush();
        $setting = $this->checkInManager->getSetting();
        $token = $setting->getStoreCheckInToken();
        self::assertNotNull($token);
        $path = '/espace-client/presence-magasin/'.$token;

        $this->client->request('GET', $path);
        self::assertResponseRedirects('/connexion');

        $this->client->loginUser($customer);
        $crawler = $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Bonjour');
        self::assertSelectorExists(sprintf('form[action="%s"][method="post"]', $path));
        $csrfToken = $crawler->filter(sprintf('form[action="%s"] input[name="_token"]', $path))->attr('value');

        $this->client->request('POST', $path, ['_token' => $csrfToken]);
        self::assertResponseRedirects($path);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.store-check-in-state', 'Présence déjà signalée');
        self::assertSame(1, $this->checkInRepository->countUnresolved());

        $checkIn = $this->checkInRepository->findUnresolvedForCustomer($customer);
        self::assertInstanceOf(CustomerCheckIn::class, $checkIn);
        self::assertSame(1, $checkIn->getScanCount());

        $crawler = $this->client->request('GET', $path);
        $csrfToken = $crawler->filter(sprintf('form[action="%s"] input[name="_token"]', $path))->attr('value');
        $this->client->request('POST', $path, ['_token' => $csrfToken]);
        self::assertResponseRedirects($path);
        self::assertSame(1, $this->checkInRepository->countUnresolved());
        self::assertSame(1, $checkIn->getScanCount(), 'Le second scan immédiat doit être absorbé par l’anti-spam.');

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.notification-card--check-in', 'attend au comptoir');
        self::assertSelectorTextContains('.notification-card--check-in', $customer->getEmail());

        $openPath = sprintf('/admin/presences-magasin/%d/ouvrir-fidelite', $checkIn->getId());
        $adminToken = $crawler->filter(sprintf('form[action="%s"] input[name="_token"]', $openPath))->attr('value');
        $this->client->request('POST', $openPath, ['_token' => $adminToken]);
        self::assertResponseRedirects(sprintf('/admin/fidelite/%d', $customer->getId()));
        self::assertSame(0, $this->checkInRepository->countUnresolved());
        $this->entityManager->clear();
        $resolvedCheckIn = $this->checkInRepository->find($checkIn->getId());
        self::assertInstanceOf(CustomerCheckIn::class, $resolvedCheckIn);
        self::assertSame(CustomerCheckIn::RESOLUTION_LOYALTY_OPENED, $resolvedCheckIn->getResolution());
    }

    #[Test]
    public function invalidDisabledAndRegeneratedQrCodesAreRejected(): void
    {
        $customer = $this->user('check-in-security@mobileclinic.fr', '+33609000102');
        $this->entityManager->flush();
        $this->client->loginUser($customer);
        $setting = $this->checkInManager->getSetting();
        $oldToken = $setting->getStoreCheckInToken();
        self::assertNotNull($oldToken);

        $this->client->request('GET', '/espace-client/presence-magasin/'.str_repeat('a', 64));
        self::assertResponseStatusCodeSame(404);

        $this->checkInManager->updateSettings(false, 15);
        $this->client->request('GET', '/espace-client/presence-magasin/'.$oldToken);
        self::assertResponseStatusCodeSame(404);

        $this->checkInManager->updateSettings(true, 15);
        $this->checkInManager->regenerateToken();
        $newToken = $this->checkInManager->getSetting()->getStoreCheckInToken();
        self::assertNotNull($newToken);
        self::assertNotSame($oldToken, $newToken);

        $this->client->request('GET', '/espace-client/presence-magasin/'.$oldToken);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/espace-client/presence-magasin/'.$newToken);
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function expiredArrivalRemainsVisibleUntilAdminDismissesIt(): void
    {
        $admin = $this->user('check-in-expired-admin@mobileclinic.fr', '+33609000992', ['ROLE_ADMIN']);
        $customer = $this->user('check-in-expired-client@mobileclinic.fr', '+33609000103');
        $checkIn = (new CustomerCheckIn())
            ->setCustomer($customer)
            ->registerScan(new \DateTimeImmutable('-20 minutes'), new \DateTimeImmutable('-5 minutes'));
        $this->entityManager->persist($checkIn);
        $this->entityManager->flush();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.notification-card--check-in', 'Présence à vérifier');
        self::assertSame(1, $this->checkInRepository->countUnresolved());

        $dismissPath = sprintf('/api/admin/presences-magasin/%d/classer', $checkIn->getId());
        $csrfToken = $crawler->filter(sprintf('form[action="%s"] input[name="_token"]', $dismissPath))->attr('value');
        $this->client->request('POST', $dismissPath, [
            '_token' => $csrfToken,
        ], [], [
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true);
        self::assertTrue($payload['success'] ?? false);
        self::assertSame(0, $this->checkInRepository->countUnresolved());
        $resolvedCheckIn = $this->checkInRepository->find($checkIn->getId());
        self::assertInstanceOf(CustomerCheckIn::class, $resolvedCheckIn);
        self::assertSame(CustomerCheckIn::RESOLUTION_DISMISSED, $resolvedCheckIn->getResolution());
        self::assertNotEmpty($payload['fragments'] ?? []);
    }

    #[Test]
    public function adminCanManageAndDownloadTheStoreQrCode(): void
    {
        $admin = $this->user('check-in-settings-admin@mobileclinic.fr', '+33609000993', ['ROLE_ADMIN']);
        $customer = $this->user('check-in-settings-client@mobileclinic.fr', '+33609000104');
        $this->entityManager->flush();

        $this->client->loginUser($customer);
        $this->client->request('GET', '/admin/parametres-generaux/presence-magasin/qrcode.svg');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/parametres-generaux');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#settings-check-in img[src^="data:image/svg+xml;base64,"]');
        self::assertSelectorExists('a[href="/admin/parametres-generaux/presence-magasin/qrcode.svg"]');
        $settingsToken = $crawler->filter('form[action="/admin/parametres-generaux/presence-magasin"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/parametres-generaux/presence-magasin', [
            '_token' => $settingsToken,
            'store_check_in_enabled' => '1',
            'store_check_in_expiration_minutes' => '20',
        ]);
        self::assertResponseRedirects('/admin/parametres-generaux#settings-check-in');
        self::assertSame(20, $this->checkInManager->getSetting()->getStoreCheckInExpirationMinutes());

        $this->client->request('GET', '/admin/parametres-generaux/presence-magasin/qrcode.svg');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/svg+xml; charset=UTF-8');
        self::assertStringContainsString('<svg', $this->client->getResponse()->getContent() ?: '');
        self::assertStringContainsString('attachment;', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    /** @param list<string> $roles */
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
}
