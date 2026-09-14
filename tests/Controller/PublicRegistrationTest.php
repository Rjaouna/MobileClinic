<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PublicRegistrationTest extends WebTestCase
{
    use DatabaseResetTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->entityManager = $this->resetDoctrineSchema();
    }

    #[Test]
    public function loginModalShowsAccountCreationPanel(): void
    {
        $this->client->request('GET', '/connexion?auth=register');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Créer mon compte client', $this->responseContent());
        self::assertStringContainsString('/inscription', $this->responseContent());
    }

    #[Test]
    public function customerCanCreateAccountFromLoginModal(): void
    {
        $crawler = $this->client->request('GET', '/connexion?auth=register');
        $token = $crawler->filter('form[action="/inscription"] input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/inscription', [
            '_token' => $token,
            'first_name' => 'Nadia',
            'last_name' => 'Martin',
            'email' => 'nadia.martin@symaclinic.fr',
            'phone' => '06 11 22 33 44',
            'password' => 'mot-de-passe-test',
            'password_confirmation' => 'mot-de-passe-test',
        ]);

        self::assertResponseRedirects('/espace-client');
        self::assertNotNull($this->client->getCookieJar()->get('REMEMBERME'));
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mon espace');

        $customer = static::getContainer()->get(UserRepository::class)->findOneByEmail('nadia.martin@symaclinic.fr');
        self::assertInstanceOf(User::class, $customer);
        self::assertSame('+33611223344', $customer->getPhone());
        self::assertSame('Nadia Martin', $customer->getDisplayName());
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($customer, 'mot-de-passe-test'));
    }

    #[Test]
    public function loginPersistsAcrossPublicPagesAndShowsConnectedCustomer(): void
    {
        $customer = $this->existingCustomer('maya.client@symaclinic.fr', '+33611223347', 'mot-de-passe-test');
        $customer
            ->setFirstName('Maya')
            ->setLastName('Client');
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/connexion');
        $token = $crawler->filter('form[action="/connexion"] input[name="_csrf_token"]')->first()->attr('value');

        $this->client->request('POST', '/connexion', [
            '_csrf_token' => $token,
            'email' => 'maya.client@symaclinic.fr',
            'password' => 'mot-de-passe-test',
        ]);

        self::assertResponseRedirects('/apres-connexion');
        self::assertNotNull($this->client->getCookieJar()->get('REMEMBERME'));
        $this->client->followRedirect();
        self::assertResponseRedirects('/espace-client');

        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.site-actions .account-chip__name', 'Maya Client');

        $crawler = $this->client->request('GET', '/promotions');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.site-actions .account-chip__name', 'Maya Client');
        self::assertSelectorExists('.site-actions .account-chip[href="/espace-client"]');
    }

    #[Test]
    public function logoutRequiresPostAndGetRequestKeepsCurrentSession(): void
    {
        $customer = $this->existingCustomer('post-logout-client@symaclinic.fr', '+33611223348', 'mot-de-passe-test');
        $this->entityManager->flush();

        $this->client->loginUser($customer);
        $crawler = $this->client->request('GET', '/espace-client');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form.dashboard-logout-form[action="/deconnexion"][method="post"]');
        $token = $crawler->filter('form.dashboard-logout-form input[name="_csrf_token"]')->first()->attr('value');

        $this->client->request('GET', '/deconnexion');
        self::assertResponseStatusCodeSame(405);

        $this->client->request('GET', '/espace-client');
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/deconnexion', [
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/');

        $this->client->request('GET', '/espace-client');
        self::assertResponseRedirects('/connexion');
    }

    #[Test]
    public function registrationRejectsDuplicatePhone(): void
    {
        $this->existingCustomer('owner@symaclinic.fr', '+33611223345');
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/connexion?auth=register');
        $token = $crawler->filter('form[action="/inscription"] input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/inscription', [
            '_token' => $token,
            'first_name' => 'Sarah',
            'last_name' => 'Lopez',
            'email' => 'sarah.lopez@symaclinic.fr',
            'phone' => '06 11 22 33 45',
            'password' => 'mot-de-passe-test',
            'password_confirmation' => 'mot-de-passe-test',
        ]);

        self::assertResponseRedirects('/connexion?auth=register');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Ce numéro de téléphone est déjà utilisé.', $this->responseContent());
    }

    #[Test]
    public function registrationRequiresValidCsrfToken(): void
    {
        $this->client->request('POST', '/inscription', [
            '_token' => 'bad-token',
            'first_name' => 'Nadia',
            'last_name' => 'Martin',
            'email' => 'csrf-register@symaclinic.fr',
            'phone' => '06 11 22 33 46',
            'password' => 'mot-de-passe-test',
            'password_confirmation' => 'mot-de-passe-test',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    private function existingCustomer(string $email, string $phone, ?string $plainPassword = null): User
    {
        $customer = (new User())
            ->setEmail($email)
            ->setPhone($phone)
            ->setRoles(['ROLE_USER'])
            ->setIsActive(true);

        if ($plainPassword !== null) {
            $customer->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($customer, $plainPassword));
        } else {
            $customer->setPassword('hashed-password');
        }

        $this->entityManager->persist($customer);

        return $customer;
    }

    private function responseContent(): string
    {
        return $this->client->getResponse()->getContent() ?: '';
    }
}
