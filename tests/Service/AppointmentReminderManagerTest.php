<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Appointment;
use App\Entity\AppointmentNotification;
use App\Entity\LoyaltyTransaction;
use App\Entity\User;
use App\Repository\AppointmentNotificationRepository;
use App\Service\AppointmentReminderManager;
use App\Service\LoyaltyManager;
use App\Tests\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class AppointmentReminderManagerTest extends KernelTestCase
{
    use DatabaseResetTrait;

    private EntityManagerInterface $entityManager;
    private LoyaltyManager $loyaltyManager;
    private AppointmentNotificationRepository $notificationRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = $this->resetDoctrineSchema();
        $this->loyaltyManager = static::getContainer()->get(LoyaltyManager::class);
        $this->notificationRepository = static::getContainer()->get(AppointmentNotificationRepository::class);
    }

    #[Test]
    public function noReminderIsCreatedBeforeTheConfiguredDelay(): void
    {
        $this->appointmentAt('2026-09-09 14:00:00');
        $manager = $this->managerAt('2026-09-09 14:00:30');

        $result = $manager->refreshReminders();

        self::assertSame(0, $result['created']);
        self::assertSame(0, $this->notificationRepository->countActiveAppointmentReminders());
    }

    #[Test]
    public function firstReminderIsCreatedOneMinuteAfterAppointmentTime(): void
    {
        $appointment = $this->appointmentAt('2026-09-09 14:00:00');
        $manager = $this->managerAt('2026-09-09 14:01:00');

        $result = $manager->refreshReminders();
        $notification = $this->notificationRepository->findActiveAppointmentReminderFor($appointment);

        self::assertSame(1, $result['created']);
        self::assertInstanceOf(AppointmentNotification::class, $notification);
        self::assertSame(AppointmentNotification::TYPE_APPOINTMENT_DUE, $notification->getType());
        self::assertStringContainsString('doit être pris en charge', $notification->getMessage());
    }

    #[Test]
    public function reminderRefreshIsIdempotent(): void
    {
        $this->appointmentAt('2026-09-09 14:00:00');
        $manager = $this->managerAt('2026-09-09 14:01:00');

        $manager->refreshReminders();
        $manager->refreshReminders();

        self::assertSame(1, $this->notificationRepository->countActiveAppointmentReminders());
    }

    #[Test]
    public function hydratedReminderKeepsTheConfiguredLocalTimeAndStaysActive(): void
    {
        $this->appointmentAt('2026-09-09 14:00:00');
        $this->entityManager->clear();
        $manager = $this->managerAt('2026-09-09 14:01:00');

        $manager->refreshReminders();
        $manager->refreshReminders();
        $view = $manager->buildAdminView();

        self::assertSame(1, $this->notificationRepository->countActiveAppointmentReminders());
        self::assertSame(1, $view['total']);
        self::assertSame('09/09/2026 14:00', $view['items'][0]['scheduled_label']);
        self::assertStringContainsString('prévu à 14:00', $view['items'][0]['message']);
    }

    #[Test]
    public function firstReminderIsPromotedToOverdueAfterSixtyMinutesWithoutDuplicate(): void
    {
        $appointment = $this->appointmentAt('2026-09-09 14:00:00');
        $this->managerAt('2026-09-09 14:01:00')->refreshReminders();
        $firstNotification = $this->notificationRepository->findActiveAppointmentReminderFor($appointment);

        $this->managerAt('2026-09-09 15:00:00')->refreshReminders();
        $overdueNotification = $this->notificationRepository->findActiveAppointmentReminderFor($appointment);

        self::assertInstanceOf(AppointmentNotification::class, $firstNotification);
        self::assertInstanceOf(AppointmentNotification::class, $overdueNotification);
        self::assertSame($firstNotification->getId(), $overdueNotification->getId());
        self::assertSame(AppointmentNotification::TYPE_APPOINTMENT_OVERDUE, $overdueNotification->getType());
        self::assertSame(1, $this->notificationRepository->countActiveAppointmentReminders());
    }

    #[Test]
    public function completedAndCancelledAppointmentsAreNotReported(): void
    {
        $completed = $this->appointmentAt('2026-09-09 14:00:00', Appointment::STATUS_COMPLETED, 'done@symaclinic.fr', '+33603000002');
        $cancelled = $this->appointmentAt('2026-09-09 14:00:00', Appointment::STATUS_CANCELLED_BY_ADMIN, 'cancel@symaclinic.fr', '+33603000003');

        $this->managerAt('2026-09-09 15:10:00')->refreshReminders();

        self::assertNull($this->notificationRepository->findActiveAppointmentReminderFor($completed));
        self::assertNull($this->notificationRepository->findActiveAppointmentReminderFor($cancelled));
        self::assertSame(0, $this->notificationRepository->countActiveAppointmentReminders());
    }

    #[Test]
    public function rescheduledAppointmentIsCalculatedFromTheNewDate(): void
    {
        $appointment = $this->appointmentAt('2026-09-09 14:00:00');
        $this->managerAt('2026-09-09 14:01:00')->refreshReminders();
        self::assertSame(1, $this->notificationRepository->countActiveAppointmentReminders());

        $appointment
            ->setScheduledAt(new \DateTimeImmutable('2026-09-10 14:00:00', new \DateTimeZone('Europe/Paris')))
            ->markRescheduled();
        $this->entityManager->flush();

        $this->managerAt('2026-09-09 14:02:00')->refreshReminders();
        self::assertSame(0, $this->notificationRepository->countActiveAppointmentReminders());

        $this->managerAt('2026-09-10 14:01:00')->refreshReminders();
        self::assertSame(1, $this->notificationRepository->countActiveAppointmentReminders());
    }

    #[Test]
    public function notificationIsResolvedAfterAdministrativeStatusChange(): void
    {
        $appointment = $this->appointmentAt('2026-09-09 14:00:00');
        $manager = $this->managerAt('2026-09-09 14:01:00');
        $manager->refreshReminders();

        $appointment->setStatus(Appointment::STATUS_CONFIRMED);
        $this->entityManager->flush();
        $manager->refreshReminders();

        $notifications = $this->notificationRepository->findActiveAppointmentReminders();
        self::assertSame([], $notifications);
    }

    #[Test]
    public function loyaltyRewardStaysPendingUntilRepairIsCompleted(): void
    {
        $customer = $this->customer('pending-flow@symaclinic.fr', '+33603000004');
        $appointment = $this->appointmentFor($customer, '2026-09-09 14:00:00');

        $reward = $this->loyaltyManager->createPendingAppointmentReward($appointment);
        $appointment->setStatus(Appointment::STATUS_CONFIRMED);
        $this->loyaltyManager->syncAppointmentStatus($appointment);
        $appointment->setStatus(Appointment::STATUS_IN_PROGRESS);
        $this->loyaltyManager->syncAppointmentStatus($appointment);

        self::assertInstanceOf(LoyaltyTransaction::class, $reward);
        self::assertSame(LoyaltyTransaction::STATUS_PENDING, $reward->getStatus());
        self::assertSame(200, $this->loyaltyManager->buildAccountView($customer)['pending_balance_cents']);
    }

    #[Test]
    public function loyaltyRewardIsValidatedOnlyOnceAfterCompletedRepair(): void
    {
        $customer = $this->customer('completed-flow@symaclinic.fr', '+33603000005');
        $appointment = $this->appointmentFor($customer, '2026-09-09 14:00:00');

        $this->loyaltyManager->createPendingAppointmentReward($appointment);
        $this->loyaltyManager->createPendingAppointmentReward($appointment);
        $appointment->setStatus(Appointment::STATUS_COMPLETED);
        $this->loyaltyManager->syncAppointmentStatus($appointment);
        $this->loyaltyManager->syncAppointmentStatus($appointment);

        $loyalty = $this->loyaltyManager->buildAccountView($customer);
        self::assertSame(1, count($loyalty['transactions']));
        self::assertSame(200, $loyalty['available_balance_cents']);
        self::assertSame(0, $loyalty['pending_balance_cents']);
    }

    #[Test]
    public function loyaltyRewardIsCancelledWhenCustomerIsAbsent(): void
    {
        $customer = $this->customer('absent-flow@symaclinic.fr', '+33603000006');
        $appointment = $this->appointmentFor($customer, '2026-09-09 14:00:00');

        $reward = $this->loyaltyManager->createPendingAppointmentReward($appointment);
        $appointment->setStatus(Appointment::STATUS_NO_SHOW);
        $this->loyaltyManager->syncAppointmentStatus($appointment);

        self::assertInstanceOf(LoyaltyTransaction::class, $reward);
        self::assertSame(LoyaltyTransaction::STATUS_CANCELLED, $reward->getStatus());
        self::assertSame(0, $this->loyaltyManager->buildAccountView($customer)['pending_balance_cents']);
    }

    #[Test]
    public function reminderUsesEuropeParisTimezoneForCalculations(): void
    {
        $appointment = $this->appointmentAt('2026-09-09 14:00:00');
        $manager = new AppointmentReminderManager(
            $this->entityManager,
            static::getContainer()->get(\App\Repository\AppointmentRepository::class),
            $this->notificationRepository,
            static::getContainer()->get(\App\Repository\AppointmentSettingRepository::class),
            new MockClock(new \DateTimeImmutable('2026-09-09 12:01:00 UTC')),
        );

        $manager->refreshReminders();

        self::assertInstanceOf(AppointmentNotification::class, $this->notificationRepository->findActiveAppointmentReminderFor($appointment));
    }

    private function managerAt(string $now): AppointmentReminderManager
    {
        return new AppointmentReminderManager(
            $this->entityManager,
            static::getContainer()->get(\App\Repository\AppointmentRepository::class),
            $this->notificationRepository,
            static::getContainer()->get(\App\Repository\AppointmentSettingRepository::class),
            new MockClock($now, 'Europe/Paris'),
        );
    }

    private function appointmentAt(
        string $scheduledAt,
        string $status = Appointment::STATUS_PENDING,
        string $email = 'reminder-client@symaclinic.fr',
        string $phone = '+33603000001',
    ): Appointment {
        $customer = $this->customer($email, $phone);
        $appointment = $this->appointmentFor($customer, $scheduledAt);
        $appointment->setStatus($status);
        $this->entityManager->flush();

        return $appointment;
    }

    private function appointmentFor(User $customer, string $scheduledAt): Appointment
    {
        $appointment = (new Appointment())
            ->setCustomer($customer)
            ->setEmail($customer->getEmail())
            ->setPhone($customer->getPhone())
            ->setDevice('iPhone')
            ->setProblem('Écran cassé')
            ->setScheduledAt(new \DateTimeImmutable($scheduledAt, new \DateTimeZone('Europe/Paris')))
            ->setDurationMinutes(30);

        $this->entityManager->persist($appointment);
        $this->entityManager->flush();

        return $appointment;
    }

    private function customer(string $email, string $phone): User
    {
        $customer = (new User())
            ->setEmail($email)
            ->setPhone($phone)
            ->setRoles(['ROLE_USER'])
            ->setPassword('hashed-password');

        $this->entityManager->persist($customer);

        return $customer;
    }
}
