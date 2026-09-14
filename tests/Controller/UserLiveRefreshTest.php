<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Appointment;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\LoyaltyManager;
use App\Tests\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserLiveRefreshTest extends WebTestCase
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
    public function liveRefreshReturnsCustomerAppointmentAndLoyaltyFragments(): void
    {
        $customer = $this->user('live-client@symaclinic.fr', '+33603000001')
            ->setFirstName('Sarah')
            ->setLastName('Martin');
        $appointment = $this->appointment($customer);
        $this->entityManager->flush();
        $this->loyaltyManager->createPendingAppointmentReward($appointment);

        $this->client->loginUser($customer);
        $this->client->request('GET', sprintf('/api/espace-client/actualisation?appointment_id=%d', $appointment->getId()));

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true);
        self::assertTrue($payload['success'] ?? false);

        $fragments = $this->fragmentsBySelector($payload['fragments'] ?? []);
        self::assertArrayHasKey('#user-dashboard-overview', $fragments);
        self::assertArrayHasKey('#user-dashboard-store-reservations', $fragments);
        self::assertArrayHasKey('#user-reservation-list', $fragments);
        self::assertArrayHasKey('#user-loyalty-card', $fragments);
        self::assertArrayHasKey('#user-loyalty-history', $fragments);
        self::assertArrayHasKey('#user-profile-summary', $fragments);
        self::assertArrayHasKey('#user-reservation-detail-'.$appointment->getId(), $fragments);
        self::assertStringContainsString('Batterie faible', $fragments['#user-reservation-list']);
        self::assertStringContainsString('En attente', $fragments['#user-reservation-detail-'.$appointment->getId()]);
        self::assertStringContainsString('Sarah', $fragments['#user-profile-summary']);
        self::assertStringContainsString('2 €', $fragments['#user-loyalty-card']);
    }

    #[Test]
    public function customerCanUpdateFirstAndLastNameWithAjax(): void
    {
        $customer = $this->user('profile-client@symaclinic.fr', '+33603000002');
        $this->entityManager->flush();

        $this->client->loginUser($customer);
        $crawler = $this->client->request('GET', '/espace-client/profil');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-user-live-refresh]');
        $token = $crawler->filter('form[action="/espace-client/profil/informations"] input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/espace-client/profil/informations', [
            '_token' => $token,
            'first_name' => 'Nadia',
            'last_name' => 'Saidi',
        ], [], [
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true);
        self::assertTrue($payload['success'] ?? false);

        $fragments = $this->fragmentsBySelector($payload['fragments'] ?? []);
        self::assertStringContainsString('Nadia', $fragments['#user-profile-summary'] ?? '');
        self::assertStringContainsString('Saidi', $fragments['#user-profile-summary'] ?? '');

        $this->entityManager->clear();
        $updated = static::getContainer()->get(UserRepository::class)->find($customer->getId());
        self::assertInstanceOf(User::class, $updated);
        self::assertSame('Nadia', $updated->getFirstName());
        self::assertSame('Saidi', $updated->getLastName());
    }

    #[Test]
    public function liveRefreshShowsLoyaltyBackInPendingAfterAppointmentReturnsToPending(): void
    {
        $customer = $this->user('loyalty-refresh-client@symaclinic.fr', '+33603000003');
        $appointment = $this->appointment($customer);
        $this->entityManager->flush();
        $this->loyaltyManager->createPendingAppointmentReward($appointment);

        $appointment->setStatus(Appointment::STATUS_COMPLETED);
        $this->loyaltyManager->syncAppointmentStatus($appointment);
        $appointment->setStatus(Appointment::STATUS_PENDING);
        $this->loyaltyManager->syncAppointmentStatus($appointment);

        $this->client->loginUser($customer);
        $this->client->request('GET', '/api/espace-client/actualisation');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true);
        $fragments = $this->fragmentsBySelector($payload['fragments'] ?? []);
        $card = new Crawler($fragments['#user-loyalty-card'] ?? '');

        self::assertSame('0 €', trim($card->filter('.loyalty-amount--available strong')->text()));
        self::assertSame('2 €', trim($card->filter('.loyalty-amount--pending strong')->text()));
        self::assertStringContainsString('En attente', $card->filter('.loyalty-card__header')->text());
    }

    /**
     * @param list<array<string, mixed>> $fragments
     * @return array<string, string>
     */
    private function fragmentsBySelector(array $fragments): array
    {
        $indexed = [];

        foreach ($fragments as $fragment) {
            if (isset($fragment['selector'], $fragment['html']) && is_string($fragment['selector']) && is_string($fragment['html'])) {
                $indexed[$fragment['selector']] = $fragment['html'];
            }
        }

        return $indexed;
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

    private function appointment(User $customer): Appointment
    {
        $appointment = (new Appointment())
            ->setCustomer($customer)
            ->setEmail($customer->getEmail())
            ->setPhone($customer->getPhone())
            ->setDevice('iPhone')
            ->setProblem('Batterie faible')
            ->setScheduledAt(new \DateTimeImmutable('+1 day', new \DateTimeZone('Europe/Paris')))
            ->setDurationMinutes(30);

        $this->entityManager->persist($appointment);

        return $appointment;
    }
}
