<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Appointment;
use App\Entity\LoyaltyAccount;
use App\Entity\LoyaltyTransaction;
use App\Entity\ProductReservation;
use App\Entity\ProductReservationItem;
use App\Entity\User;
use App\Service\CustomerNotificationMailer;
use App\Service\GeneralSettingManager;
use App\Tests\DatabaseResetTrait;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class CustomerNotificationMailerTest extends KernelTestCase
{
    use DatabaseResetTrait;

    private CustomerNotificationMailer $notificationMailer;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetDoctrineSchema();
        $container = static::getContainer();
        $this->notificationMailer = new CustomerNotificationMailer(
            $container->get(MailerInterface::class),
            $container->get(GeneralSettingManager::class),
            $container->get(UrlGeneratorInterface::class),
            $container->get(LoggerInterface::class),
            true,
            'notifications@example.com',
            'admin@example.com',
            'https://mobile-clinic.example',
        );
    }

    #[Test]
    public function everyBusinessNotificationIsDeliveredOnlyToTheCustomer(): void
    {
        $customer = (new User())
            ->setEmail('client@example.com')
            ->setFirstName('Camille')
            ->setLastName('Martin')
            ->setPhone('+33600000000');
        $appointment = (new Appointment())
            ->setCustomer($customer)
            ->setEmail($customer->getEmail())
            ->setPhone($customer->getPhone())
            ->setDevice('iPhone')
            ->setProblem('Écran cassé')
            ->setScheduledAt(new \DateTimeImmutable('+2 days'));
        $account = (new LoyaltyAccount())
            ->setCustomer($customer)
            ->setAvailableBalanceCents(500);
        $transaction = (new LoyaltyTransaction())
            ->setAccount($account)
            ->setAmountCents(200)
            ->setType(LoyaltyTransaction::TYPE_ADJUSTMENT)
            ->setReason('Geste commercial')
            ->markValidated();
        $reservation = (new ProductReservation())
            ->setCustomer($customer)
            ->setStorePhone('03 20 50 71 03')
            ->setExpiresAt(new \DateTimeImmutable('+1 day'))
            ->setTotalCents(2990)
            ->setLoyaltyUsedCents(200)
            ->addItem((new ProductReservationItem())
                ->setProductName('Chargeur USB-C')
                ->setUnitNormalPriceCents(3990)
                ->setUnitPromotionalPriceCents(2990));

        $this->notificationMailer->sendAccountCreated($customer, 'TEMPORAIRE-123');
        $this->notificationMailer->sendAppointmentCreated($appointment);
        $this->notificationMailer->sendLoyaltyMovement($transaction);
        $this->notificationMailer->sendProductReservationChanged($reservation, 'created');
        self::assertTrue($this->notificationMailer->sendTestNotification('test@example.com'));

        self::assertEmailCount(5);
        $messages = self::getMailerMessages();

        foreach ([0, 1, 2, 3] as $index) {
            self::assertSame('client@example.com', $messages[$index]->getTo()[0]->getAddress());
        }

        self::assertSame('test@example.com', $messages[4]->getTo()[0]->getAddress());
        $customerAccountEmail = self::getMailerMessage(0);
        self::assertNotNull($customerAccountEmail);
        self::assertEmailHtmlBodyContains($customerAccountEmail, 'TEMPORAIRE-123');
        self::assertEmailTextBodyContains($customerAccountEmail, 'TEMPORAIRE-123');

        foreach (self::getMailerMessages() as $email) {
            self::assertEmailHtmlBodyContains($email, '18 Rue du Sec Arembault');
            self::assertEmailHtmlBodyContains($email, '03 20 50 71 03');
        }
    }
}
