<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Appointment;
use App\Entity\User;
use App\Repository\AppointmentNotificationRepository;
use App\Repository\AppointmentRepository;
use App\Repository\UserRepository;
use App\Service\LoyaltyManager;
use App\Tests\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminAppointmentReminderApiTest extends WebTestCase
{
    use DatabaseResetTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private LoyaltyManager $loyaltyManager;
    private AppointmentNotificationRepository $notificationRepository;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->entityManager = $this->resetDoctrineSchema();
        $this->loyaltyManager = static::getContainer()->get(LoyaltyManager::class);
        $this->notificationRepository = static::getContainer()->get(AppointmentNotificationRepository::class);
    }

    #[Test]
    public function reminderEndpointsRejectNonAdminUsers(): void
    {
        $customer = $this->user('api-user@symaclinic.fr', '+33604000001');
        $this->entityManager->flush();

        $this->client->loginUser($customer);
        $this->client->request('GET', '/api/admin/rendez-vous/rappels');

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function statusUpdateRequiresValidCsrfToken(): void
    {
        $admin = $this->user('api-admin-csrf@symaclinic.fr', '+33604000999', ['ROLE_ADMIN']);
        $appointment = $this->appointment($this->user('api-client-csrf@symaclinic.fr', '+33604000002'));
        $this->entityManager->flush();

        $this->client->loginUser($admin);
        $this->client->request('POST', sprintf('/api/admin/rendez-vous/%d/statut', $appointment->getId()), [
            '_token' => 'bad-token',
            'status' => Appointment::STATUS_COMPLETED,
            'confirm_status' => '1',
        ]);

        self::assertResponseStatusCodeSame(403);
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true);
        self::assertFalse($payload['success'] ?? true);
    }

    #[Test]
    public function reminderEndpointReturnsStructuredJsonAndFragments(): void
    {
        $admin = $this->user('api-admin-json@symaclinic.fr', '+33604000998', ['ROLE_ADMIN']);
        $this->appointment($this->user('api-client-json@symaclinic.fr', '+33604000003'));
        $this->entityManager->flush();

        $this->client->loginUser($admin);
        $this->client->request('GET', '/api/admin/rendez-vous/rappels');

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true);
        self::assertTrue($payload['success'] ?? false);
        self::assertGreaterThanOrEqual(1, $payload['badge_count'] ?? 0);
        self::assertNotEmpty($payload['notifications'] ?? []);
        self::assertNotEmpty($payload['fragments'] ?? []);
        self::assertSame('APPOINTMENT_OVERDUE', $payload['notifications'][0]['type'] ?? null);
        $notificationHtml = '';

        foreach ($payload['fragments'] as $fragment) {
            if (($fragment['selector'] ?? null) === '#admin-notification-center') {
                $notificationHtml = (string) ($fragment['html'] ?? '');
            }
        }

        self::assertStringNotContainsString('aria-labelledby="admin-notification-title" hidden', $notificationHtml);

        $notificationId = $payload['notifications'][0]['id'] ?? null;
        $this->client->request('GET', '/api/admin/rendez-vous/rappels');

        self::assertResponseIsSuccessful();
        $refreshedPayload = json_decode($this->client->getResponse()->getContent() ?: '{}', true);
        self::assertNotEmpty($refreshedPayload['notifications'] ?? []);
        self::assertSame($notificationId, $refreshedPayload['notifications'][0]['id'] ?? null);
        self::assertSame(1, $this->notificationRepository->countActiveAppointmentReminders());
    }

    #[Test]
    public function ajaxStatusUpdateResolvesReminderAndKeepsLoyaltyRules(): void
    {
        $admin = $this->user('api-admin-update@symaclinic.fr', '+33604000997', ['ROLE_ADMIN']);
        $customer = $this->user('api-client-update@symaclinic.fr', '+33604000004');
        $appointment = $this->appointment($customer);
        $this->entityManager->flush();
        $this->loyaltyManager->createPendingAppointmentReward($appointment);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/rendez-vous');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->notificationRepository->countActiveAppointmentReminders());

        $token = $crawler->filter(sprintf('form[action="/api/admin/rendez-vous/%d/statut"] input[name="_token"]', $appointment->getId()))
            ->first()
            ->attr('value');

        $this->client->request('POST', sprintf('/api/admin/rendez-vous/%d/statut', $appointment->getId()), [
            '_token' => $token,
            'status' => Appointment::STATUS_COMPLETED,
            'confirm_status' => '1',
            'admin_note' => 'Réparation validée en test.',
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true);
        self::assertTrue($payload['success'] ?? false);
        self::assertNotEmpty($payload['fragments'] ?? []);

        $updatedAppointment = static::getContainer()->get(AppointmentRepository::class)->find($appointment->getId());
        self::assertInstanceOf(Appointment::class, $updatedAppointment);
        self::assertSame(Appointment::STATUS_COMPLETED, $updatedAppointment->getStatus());
        self::assertSame($admin->getEmail(), $updatedAppointment->getStatusChangedByEmail());
        self::assertSame(0, $this->notificationRepository->countActiveAppointmentReminders());

        $loyalty = $this->loyaltyManager->buildAccountView($customer);
        self::assertSame(200, $loyalty['available_balance_cents']);
        self::assertSame(0, $loyalty['pending_balance_cents']);
    }

    #[Test]
    public function ajaxStatusUpdateBackToPendingRestoresLoyaltyCard(): void
    {
        $admin = $this->user('api-admin-back-pending@symaclinic.fr', '+33604000995', ['ROLE_ADMIN']);
        $customer = $this->user('api-client-back-pending@symaclinic.fr', '+33604000006');
        $appointment = $this->appointment($customer);
        $this->entityManager->flush();
        $this->loyaltyManager->createPendingAppointmentReward($appointment);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/rendez-vous');
        self::assertResponseIsSuccessful();

        $token = $crawler->filter(sprintf('form[action="/api/admin/rendez-vous/%d/statut"] input[name="_token"]', $appointment->getId()))
            ->first()
            ->attr('value');

        $this->client->request('POST', sprintf('/api/admin/rendez-vous/%d/statut', $appointment->getId()), [
            '_token' => $token,
            'status' => Appointment::STATUS_COMPLETED,
            'confirm_status' => '1',
        ]);

        self::assertResponseIsSuccessful();
        $loyalty = $this->loyaltyManager->buildAccountView($customer);
        self::assertSame(200, $loyalty['available_balance_cents']);
        self::assertSame(0, $loyalty['pending_balance_cents']);

        $this->client->request('POST', sprintf('/api/admin/rendez-vous/%d/statut', $appointment->getId()), [
            '_token' => $token,
            'status' => Appointment::STATUS_PENDING,
            'confirm_status' => '1',
        ]);

        self::assertResponseIsSuccessful();
        $customerId = $customer->getId();
        $this->entityManager->clear();

        $customer = static::getContainer()->get(UserRepository::class)->find($customerId);
        self::assertInstanceOf(User::class, $customer);

        $loyalty = $this->loyaltyManager->buildAccountView($customer);
        self::assertSame(0, $loyalty['available_balance_cents']);
        self::assertSame(200, $loyalty['pending_balance_cents']);
    }

    #[Test]
    public function reminderBlockCanMarkCustomerAbsentWithQuickAction(): void
    {
        $admin = $this->user('api-admin-absent@symaclinic.fr', '+33604000994', ['ROLE_ADMIN']);
        $customer = $this->user('api-client-absent@symaclinic.fr', '+33604000007');
        $appointment = $this->appointment($customer);
        $this->entityManager->flush();
        $this->loyaltyManager->createPendingAppointmentReward($appointment);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/rendez-vous');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter(sprintf(
            'form[action="/api/admin/rendez-vous/%d/statut"] input[name="status"][value="%s"]',
            $appointment->getId(),
            Appointment::STATUS_NO_SHOW,
        ))->count());

        $token = $crawler->filter(sprintf('form[action="/api/admin/rendez-vous/%d/statut"] input[name="_token"]', $appointment->getId()))
            ->first()
            ->attr('value');

        $this->client->request('POST', sprintf('/api/admin/rendez-vous/%d/statut', $appointment->getId()), [
            '_token' => $token,
            'status' => Appointment::STATUS_NO_SHOW,
            'confirm_status' => '1',
            'admin_note' => 'Client absent depuis le rappel admin.',
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true);
        self::assertTrue($payload['success'] ?? false);
        $notificationHtml = '';

        foreach ($payload['fragments'] ?? [] as $fragment) {
            if (($fragment['selector'] ?? null) === '#admin-notification-center') {
                $notificationHtml = (string) ($fragment['html'] ?? '');
            }
        }

        self::assertStringContainsString('Aucune notification active', $notificationHtml);
        self::assertStringContainsString('aria-labelledby="admin-notification-title" hidden', $notificationHtml);
        self::assertStringNotContainsString('api-client-absent@symaclinic.fr', $notificationHtml);

        $updatedAppointment = static::getContainer()->get(AppointmentRepository::class)->find($appointment->getId());
        self::assertInstanceOf(Appointment::class, $updatedAppointment);
        self::assertSame(Appointment::STATUS_NO_SHOW, $updatedAppointment->getStatus());
        self::assertSame(0, $this->notificationRepository->countActiveAppointmentReminders());

        $loyalty = $this->loyaltyManager->buildAccountView($customer);
        self::assertSame(0, $loyalty['available_balance_cents']);
        self::assertSame(0, $loyalty['pending_balance_cents']);
        self::assertSame(200, $loyalty['cancelled_gains_cents']);
    }

    #[Test]
    public function ajaxStatusUpdateCanChangeTheAppointmentDate(): void
    {
        $admin = $this->user('api-admin-date@symaclinic.fr', '+33604000996', ['ROLE_ADMIN']);
        $customer = $this->user('api-client-date@symaclinic.fr', '+33604000005');
        $appointment = $this->appointment($customer);
        $this->entityManager->flush();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/rendez-vous');
        self::assertResponseIsSuccessful();

        $token = $crawler->filter(sprintf('form[action="/api/admin/rendez-vous/%d/statut"] input[name="_token"]', $appointment->getId()))
            ->first()
            ->attr('value');
        $newDate = (new \DateTimeImmutable('tomorrow 10:00', new \DateTimeZone('Europe/Paris')))->format('Y-m-d\TH:i');

        $this->client->request('POST', sprintf('/api/admin/rendez-vous/%d/statut', $appointment->getId()), [
            '_token' => $token,
            'status' => Appointment::STATUS_PENDING,
            'confirm_status' => '1',
            'scheduled_at' => $newDate,
            'duration_minutes' => '45',
            'admin_note' => 'Créneau déplacé en test.',
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true);
        self::assertTrue($payload['success'] ?? false);

        $updatedAppointment = static::getContainer()->get(AppointmentRepository::class)->find($appointment->getId());
        self::assertInstanceOf(Appointment::class, $updatedAppointment);
        self::assertSame(Appointment::STATUS_PENDING, $updatedAppointment->getStatus());
        self::assertSame(45, $updatedAppointment->getDurationMinutes());
        self::assertSame(
            (new \DateTimeImmutable($newDate, new \DateTimeZone('Europe/Paris')))->format('Y-m-d H:i'),
            $updatedAppointment->getScheduledAt()->format('Y-m-d H:i'),
        );
        self::assertSame($admin->getEmail(), $updatedAppointment->getStatusChangedByEmail());
        self::assertSame(0, $this->notificationRepository->countActiveAppointmentReminders());
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
            ->setProblem('Écran cassé')
            ->setScheduledAt(new \DateTimeImmutable('-2 hours', new \DateTimeZone('Europe/Paris')))
            ->setDurationMinutes(30);

        $this->entityManager->persist($appointment);

        return $appointment;
    }
}
