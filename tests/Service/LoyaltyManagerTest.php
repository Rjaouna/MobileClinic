<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Appointment;
use App\Entity\LoyaltyTransaction;
use App\Entity\User;
use App\Service\LoyaltyManager;
use App\Tests\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LoyaltyManagerTest extends KernelTestCase
{
    use DatabaseResetTrait;

    private EntityManagerInterface $entityManager;
    private LoyaltyManager $loyaltyManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = $this->resetDoctrineSchema();
        $this->loyaltyManager = static::getContainer()->get(LoyaltyManager::class);
    }

    #[Test]
    public function appointmentRewardStaysPendingUntilCompletion(): void
    {
        $customer = $this->customer();
        $appointment = $this->appointment($customer);

        $reward = $this->loyaltyManager->createPendingAppointmentReward($appointment);
        $loyalty = $this->loyaltyManager->buildAccountView($customer);

        self::assertInstanceOf(LoyaltyTransaction::class, $reward);
        self::assertSame(200, $reward->getAmountCents());
        self::assertTrue($reward->isPending());
        self::assertSame(0, $loyalty['available_balance_cents']);
        self::assertSame(200, $loyalty['pending_balance_cents']);
    }

    #[Test]
    public function completingAppointmentValidatesRewardOnlyOnce(): void
    {
        $customer = $this->customer('client-double@symaclinic.fr', '+33601000002');
        $appointment = $this->appointment($customer);

        $this->loyaltyManager->createPendingAppointmentReward($appointment);
        $this->loyaltyManager->createPendingAppointmentReward($appointment);

        $appointment->setStatus(Appointment::STATUS_COMPLETED);
        $this->loyaltyManager->syncAppointmentStatus($appointment);
        $this->loyaltyManager->syncAppointmentStatus($appointment);

        $loyalty = $this->loyaltyManager->buildAccountView($customer);
        self::assertSame(1, count($loyalty['transactions']));
        self::assertSame(200, $loyalty['available_balance_cents']);
        self::assertSame(0, $loyalty['pending_balance_cents']);
        self::assertTrue($loyalty['transactions'][0]->isValidated());
    }

    #[Test]
    public function cancelledAppointmentCancelsPendingReward(): void
    {
        $customer = $this->customer('client-cancel@symaclinic.fr', '+33601000003');
        $appointment = $this->appointment($customer);

        $reward = $this->loyaltyManager->createPendingAppointmentReward($appointment);
        $appointment->setStatus(Appointment::STATUS_CANCELLED_BY_CLIENT);
        $this->loyaltyManager->syncAppointmentStatus($appointment);

        $loyalty = $this->loyaltyManager->buildAccountView($customer);
        self::assertSame(LoyaltyTransaction::STATUS_CANCELLED, $reward?->getStatus());
        self::assertSame(0, $loyalty['available_balance_cents']);
        self::assertSame(0, $loyalty['pending_balance_cents']);
        self::assertSame(200, $loyalty['cancelled_gains_cents']);
    }

    #[Test]
    public function validatedRewardIsNotRemovedByLaterCancellation(): void
    {
        $customer = $this->customer('client-validated@symaclinic.fr', '+33601000004');
        $appointment = $this->appointment($customer);

        $reward = $this->loyaltyManager->createPendingAppointmentReward($appointment);
        $appointment->setStatus(Appointment::STATUS_COMPLETED);
        $this->loyaltyManager->syncAppointmentStatus($appointment);
        $appointment->setStatus(Appointment::STATUS_CANCELLED_BY_ADMIN);
        $this->loyaltyManager->syncAppointmentStatus($appointment);

        $loyalty = $this->loyaltyManager->buildAccountView($customer);
        self::assertSame(LoyaltyTransaction::STATUS_VALIDATED, $reward?->getStatus());
        self::assertSame(200, $loyalty['available_balance_cents']);
        self::assertSame(200, $loyalty['validated_gains_cents']);
    }

    #[Test]
    public function returningCompletedAppointmentToPendingRestoresPendingReward(): void
    {
        $customer = $this->customer('client-back-pending@symaclinic.fr', '+33601000040');
        $appointment = $this->appointment($customer);

        $reward = $this->loyaltyManager->createPendingAppointmentReward($appointment);
        $appointment->setStatus(Appointment::STATUS_COMPLETED);
        $this->loyaltyManager->syncAppointmentStatus($appointment);
        $appointment->setStatus(Appointment::STATUS_PENDING);
        $this->loyaltyManager->syncAppointmentStatus($appointment);

        $loyalty = $this->loyaltyManager->buildAccountView($customer);
        self::assertSame(LoyaltyTransaction::STATUS_PENDING, $reward?->getStatus());
        self::assertSame(0, $loyalty['available_balance_cents']);
        self::assertSame(200, $loyalty['pending_balance_cents']);
        self::assertSame(0, $loyalty['validated_gains_cents']);
    }

    #[Test]
    public function returningAbsentAppointmentToPendingRestoresPendingReward(): void
    {
        $customer = $this->customer('client-absent-back-pending@symaclinic.fr', '+33601000041');
        $appointment = $this->appointment($customer);

        $reward = $this->loyaltyManager->createPendingAppointmentReward($appointment);
        $appointment->setStatus(Appointment::STATUS_NO_SHOW);
        $this->loyaltyManager->syncAppointmentStatus($appointment);
        $appointment->setStatus(Appointment::STATUS_PENDING);
        $this->loyaltyManager->syncAppointmentStatus($appointment);

        $loyalty = $this->loyaltyManager->buildAccountView($customer);
        self::assertSame(LoyaltyTransaction::STATUS_PENDING, $reward?->getStatus());
        self::assertSame(0, $loyalty['available_balance_cents']);
        self::assertSame(200, $loyalty['pending_balance_cents']);
        self::assertSame(0, $loyalty['cancelled_gains_cents']);
    }

    #[Test]
    public function rewardAmountIsSnapshottedOnAppointmentCreation(): void
    {
        $customer = $this->customer('client-snapshot@symaclinic.fr', '+33601000005');
        $appointment = $this->appointment($customer);

        $this->loyaltyManager->updateSetting(350, true);
        $reward = $this->loyaltyManager->createPendingAppointmentReward($appointment);
        $this->loyaltyManager->updateSetting(100, true);
        $appointment->setStatus(Appointment::STATUS_COMPLETED);
        $this->loyaltyManager->syncAppointmentStatus($appointment);

        $loyalty = $this->loyaltyManager->buildAccountView($customer);
        self::assertSame(350, $reward?->getAmountCents());
        self::assertSame(350, $loyalty['available_balance_cents']);
    }

    #[Test]
    public function redeemCreatesDebitAndRejectsAmountsAboveBalance(): void
    {
        $admin = $this->customer('admin@symaclinic.fr', '+33601000999', ['ROLE_ADMIN']);
        $customer = $this->customer('client-redeem@symaclinic.fr', '+33601000006');
        $this->entityManager->flush();

        $this->loyaltyManager->manualCredit($customer, 500, 'Geste commercial', $admin);
        $debit = $this->loyaltyManager->redeem($customer, 200, 'Réparation écran', $admin);
        $loyalty = $this->loyaltyManager->buildAccountView($customer);

        self::assertSame(-200, $debit->getAmountCents());
        self::assertSame(300, $loyalty['available_balance_cents']);
        self::assertSame(200, $loyalty['redeemed_cents']);

        $this->expectException(\InvalidArgumentException::class);
        $this->loyaltyManager->redeem($customer, 400, 'Montant trop haut', $admin);
    }

    /**
     * @param list<string> $roles
     */
    private function customer(string $email = 'client@symaclinic.fr', string $phone = '+33601000001', array $roles = ['ROLE_USER']): User
    {
        $customer = (new User())
            ->setEmail($email)
            ->setPhone($phone)
            ->setRoles($roles)
            ->setPassword('hashed-password');

        $this->entityManager->persist($customer);

        return $customer;
    }

    private function appointment(User $customer): Appointment
    {
        $appointment = (new Appointment())
            ->setCustomer($customer)
            ->setEmail($customer->getEmail())
            ->setPhone($customer->getPhone())
            ->setDevice('iPhone')
            ->setProblem('Écran cassé')
            ->setScheduledAt(new \DateTimeImmutable('+2 days'))
            ->setDurationMinutes(30);

        $this->entityManager->persist($appointment);

        return $appointment;
    }
}
