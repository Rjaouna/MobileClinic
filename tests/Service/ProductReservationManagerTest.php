<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Product;
use App\Entity\ProductReservation;
use App\Entity\User;
use App\Repository\ProductRepository;
use App\Service\GeneralSettingManager;
use App\Service\LoyaltyManager;
use App\Service\ProductReservationManager;
use App\Tests\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProductReservationManagerTest extends KernelTestCase
{
    use DatabaseResetTrait;

    private EntityManagerInterface $entityManager;
    private ProductReservationManager $reservationManager;
    private LoyaltyManager $loyaltyManager;
    private GeneralSettingManager $generalSettingManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = $this->resetDoctrineSchema();
        $this->reservationManager = static::getContainer()->get(ProductReservationManager::class);
        $this->loyaltyManager = static::getContainer()->get(LoyaltyManager::class);
        $this->generalSettingManager = static::getContainer()->get(GeneralSettingManager::class);
    }

    #[Test]
    public function usingLoyaltyEmptiesCardDuringReservationAndCancellationRefundsIt(): void
    {
        $admin = $this->user('admin-store@symaclinic.fr', '+33604000999', ['ROLE_ADMIN']);
        $customer = $this->user('store-client@symaclinic.fr', '+33604000001');
        $product = $this->product('Coque renforcée', 300);
        $this->entityManager->flush();

        $this->loyaltyManager->manualCredit($customer, 500, 'Solde test boutique', $admin);
        $reservation = $this->reservationManager->createReservation($customer, [(int) $product->getId()], true);
        $loyaltyAfterReservation = $this->loyaltyManager->buildAccountView($customer);

        self::assertSame(ProductReservation::STATUS_RESERVED, $reservation->getStatus());
        self::assertSame(500, $reservation->getLoyaltyUsedCents());
        self::assertSame(0, $reservation->getPayableCents());
        self::assertSame(0, $loyaltyAfterReservation['available_balance_cents']);

        $this->reservationManager->cancelByCustomer($reservation, $customer);
        $loyaltyAfterCancellation = $this->loyaltyManager->buildAccountView($customer);

        self::assertSame(ProductReservation::STATUS_CANCELLED_BY_CUSTOMER, $reservation->getStatus());
        self::assertSame(500, $reservation->getLoyaltyRefundedCents());
        self::assertSame(500, $loyaltyAfterCancellation['available_balance_cents']);
    }

    #[Test]
    public function expiredReservationRefundsLoyaltyAndReleasesProduct(): void
    {
        $admin = $this->user('expire-admin@symaclinic.fr', '+33604000998', ['ROLE_ADMIN']);
        $customer = $this->user('expire-client@symaclinic.fr', '+33604000002');
        $product = $this->product('Chargeur USB-C', 900);
        $this->entityManager->flush();

        $this->loyaltyManager->manualCredit($customer, 300, 'Solde test expiration', $admin);
        $reservation = $this->reservationManager->createReservation($customer, [(int) $product->getId()], true);
        $reservation->setExpiresAt(new \DateTimeImmutable('-2 minutes'));
        $this->entityManager->flush();

        $result = $this->reservationManager->expireOverdueReservations();
        $loyaltyAfterExpiration = $this->loyaltyManager->buildAccountView($customer);

        self::assertSame(['expired' => 1, 'refunded_cents' => 300], $result);
        self::assertSame(ProductReservation::STATUS_EXPIRED, $reservation->getStatus());
        self::assertSame(300, $loyaltyAfterExpiration['available_balance_cents']);

        $newReservation = $this->reservationManager->createReservation($customer, [(int) $product->getId()], false);
        self::assertSame(ProductReservation::STATUS_RESERVED, $newReservation->getStatus());
    }

    #[Test]
    public function confirmedReservationDoesNotExpireAutomatically(): void
    {
        $admin = $this->user('confirm-admin@symaclinic.fr', '+33604000997', ['ROLE_ADMIN']);
        $customer = $this->user('confirm-client@symaclinic.fr', '+33604000003');
        $product = $this->product('iPhone reconditionné', 19900);
        $this->entityManager->flush();

        $reservation = $this->reservationManager->createReservation($customer, [(int) $product->getId()], false);
        $this->reservationManager->confirm($reservation, $admin, 'Retrait validé');
        $reservation->setExpiresAt(new \DateTimeImmutable('-2 minutes'));
        $this->entityManager->flush();

        $result = $this->reservationManager->expireOverdueReservations();

        self::assertSame(['expired' => 0, 'refunded_cents' => 0], $result);
        self::assertSame(ProductReservation::STATUS_CONFIRMED, $reservation->getStatus());
    }

    #[Test]
    public function withdrawnReservationMarksProductSoldAndKeepsLoyaltyConsumed(): void
    {
        $admin = $this->user('withdraw-admin@symaclinic.fr', '+33604000996', ['ROLE_ADMIN']);
        $customer = $this->user('withdraw-client@symaclinic.fr', '+33604000004');
        $product = $this->product('Coque vendue en magasin', 300);
        $this->entityManager->flush();

        $this->loyaltyManager->manualCredit($customer, 500, 'Solde test retrait', $admin);
        $reservation = $this->reservationManager->createReservation($customer, [(int) $product->getId()], true);
        $this->reservationManager->withdraw($reservation, $admin, 'Client passé en boutique');
        $loyaltyAfterWithdrawal = $this->loyaltyManager->buildAccountView($customer);

        self::assertSame(ProductReservation::STATUS_SOLD, $reservation->getStatus());
        self::assertNotNull($reservation->getWithdrawnAt());
        self::assertNull($reservation->getExpiresAt());
        self::assertSame(500, $reservation->getLoyaltyUsedCents());
        self::assertSame(0, $reservation->getRefundableLoyaltyCents());
        self::assertSame(0, $loyaltyAfterWithdrawal['available_balance_cents']);
        self::assertTrue($product->isSold());
        self::assertFalse($product->isActive());
        self::assertSame([], static::getContainer()->get(ProductRepository::class)->findForStore('Coque vendue'));

        $this->expectException(\InvalidArgumentException::class);
        $this->reservationManager->createReservation($customer, [(int) $product->getId()], false);
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
            ->setPassword('hashed-password')
            ->setIsActive(true);

        $this->entityManager->persist($user);

        return $user;
    }

    private function product(string $name, int $promotionalPriceCents): Product
    {
        $product = (new Product())
            ->setName($name)
            ->setDescription('Article disponible uniquement en retrait magasin.')
            ->setNormalPriceCents($promotionalPriceCents + 500)
            ->setPromotionalPriceCents($promotionalPriceCents)
            ->setCustomAttributes([
                ['label' => 'Couleur', 'value' => 'Noir'],
            ]);

        $this->entityManager->persist($product);

        return $product;
    }
}
