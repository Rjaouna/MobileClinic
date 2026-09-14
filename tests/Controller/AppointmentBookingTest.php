<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AppointmentAvailability;
use App\Entity\User;
use App\Repository\AppointmentRepository;
use App\Service\AppointmentScheduler;
use App\Tests\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AppointmentBookingTest extends WebTestCase
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
    public function connectedCustomerSeesSimplifiedAppointmentWizard(): void
    {
        $customer = $this->customer('client-rdv@symaclinic.fr', '+33601020304');
        $this->entityManager->flush();

        $this->client->loginUser($customer);
        $crawler = $this->client->request('GET', '/espace-client');

        self::assertResponseIsSuccessful();
        self::assertSame(2, $crawler->filter('#appointment-modal [data-booking-step-indicator]')->count());
        self::assertSelectorNotExists('#appointment-modal input#appointment-modal-email');
        self::assertSelectorNotExists('#appointment-modal input#appointment-modal-phone');
        self::assertSelectorExists('#appointment-modal input[type="hidden"][name="email"][value="client-rdv@symaclinic.fr"]');
        self::assertSelectorExists('#appointment-modal input[type="hidden"][name="phone"][value="+33601020304"]');
        self::assertStringContainsString('Vos coordonnées sont déjà liées à votre compte.', $this->responseContent());
    }

    #[Test]
    public function connectedCustomerCanBookWithoutPostingContactFields(): void
    {
        $customer = $this->customer('book-rdv@symaclinic.fr', '+33601020305');
        $this->addTomorrowAvailability();
        $this->entityManager->flush();

        $slotDays = static::getContainer()->get(AppointmentScheduler::class)->getBookableSlotDays();
        self::assertNotEmpty($slotDays);
        $slot = $slotDays[0]['slots'][0]['value'];

        $this->client->loginUser($customer);
        $crawler = $this->client->request('GET', '/espace-client');
        $token = $crawler->filter('#appointment-modal form input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/rendez-vous', [
            '_token' => $token,
            '_redirect_to' => 'user_dashboard',
            'device' => 'iphone',
            'problem' => 'screen',
            'slot' => $slot,
        ]);

        self::assertResponseRedirects('/espace-client/rendez-vous');

        $appointments = static::getContainer()->get(AppointmentRepository::class)->findForUser($customer);
        self::assertCount(1, $appointments);
        self::assertSame('book-rdv@symaclinic.fr', $appointments[0]->getEmail());
        self::assertSame('+33601020305', $appointments[0]->getPhone());
    }

    private function customer(string $email, string $phone): User
    {
        $customer = (new User())
            ->setEmail($email)
            ->setPhone($phone)
            ->setRoles(['ROLE_USER'])
            ->setPassword('hashed-password')
            ->setIsActive(true);

        $this->entityManager->persist($customer);

        return $customer;
    }

    private function addTomorrowAvailability(): void
    {
        $tomorrow = new \DateTimeImmutable('tomorrow', new \DateTimeZone('Europe/Paris'));
        $availability = (new AppointmentAvailability())
            ->setDayOfWeek((int) $tomorrow->format('N'))
            ->setStartTime(new \DateTimeImmutable('10:00'))
            ->setEndTime(new \DateTimeImmutable('11:00'))
            ->setSlotDurationMinutes(30)
            ->setIsEnabled(true);

        $this->entityManager->persist($availability);
    }

    private function responseContent(): string
    {
        return $this->client->getResponse()->getContent() ?: '';
    }
}
