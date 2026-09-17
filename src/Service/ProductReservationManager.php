<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Product;
use App\Entity\ProductReservation;
use App\Entity\ProductReservationItem;
use App\Entity\User;
use App\Repository\ProductRepository;
use App\Repository\ProductReservationRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ProductReservationManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductRepository $productRepository,
        private readonly ProductReservationRepository $reservationRepository,
        private readonly LoyaltyManager $loyaltyManager,
        private readonly GeneralSettingManager $generalSettingManager,
        private readonly CustomerNotificationMailer $notificationMailer,
    ) {
    }

    /**
     * @param list<int> $productIds
     *
     * @return list<Product>
     */
    public function getCartProducts(array $productIds): array
    {
        $this->expireOverdueReservations();

        return $this->productRepository->findActiveByIds($productIds);
    }

    /** @param list<int> $productIds */
    public function createReservation(User $customer, array $productIds, bool $useLoyalty): ProductReservation
    {
        $loyaltyTransaction = null;
        $reservation = $this->entityManager->wrapInTransaction(function () use ($customer, $productIds, $useLoyalty, &$loyaltyTransaction): ProductReservation {
            $products = $this->productRepository->findActiveByIds($productIds);

            if ($products === []) {
                throw new \InvalidArgumentException('Ajoutez au moins un article au panier.');
            }

            if (count($products) !== count(array_values(array_unique($productIds)))) {
                throw new \InvalidArgumentException('Un article du panier n’est plus disponible.');
            }

            foreach ($products as $product) {
                if ($product->isSold()) {
                    throw new \InvalidArgumentException(sprintf('L’article "%s" a déjà été vendu.', $product->getName()));
                }

                if ($this->productRepository->isBlocked($product)) {
                    throw new \InvalidArgumentException(sprintf('L’article "%s" est déjà réservé.', $product->getName()));
                }
            }

            $setting = $this->generalSettingManager->getSetting();
            $expiresAt = (new \DateTimeImmutable())->modify(sprintf('+%d minutes', $setting->getProductReservationHoldMinutes()));
            $reservation = (new ProductReservation())
                ->setCustomer($customer)
                ->setStorePhone($setting->getStorePhone())
                ->setExpiresAt($expiresAt);
            $totalCents = 0;

            foreach ($products as $product) {
                $item = (new ProductReservationItem())
                    ->setProduct($product)
                    ->setProductName($product->getName())
                    ->setPhotoPath($product->getPhotoPath())
                    ->setUnitNormalPriceCents($product->getNormalPriceCents())
                    ->setUnitPromotionalPriceCents($product->getPromotionalPriceCents())
                    ->setQuantity(1)
                    ->setCustomAttributes($product->getCustomAttributes());

                $totalCents += $item->getLineTotalCents();
                $reservation->addItem($item);
            }

            $reservation->setTotalCents($totalCents);

            if ($useLoyalty) {
                $availableBalance = $this->loyaltyManager->buildAccountView($customer)['available_balance_cents'];
                $reservation->setLoyaltyUsedCents($availableBalance);
            }

            $this->entityManager->persist($reservation);
            $this->entityManager->flush();
            $loyaltyTransaction = $this->loyaltyManager->redeemForProductReservation(
                $reservation,
                $reservation->getLoyaltyUsedCents(),
                false,
            );

            return $reservation;
        });

        $this->notificationMailer->sendProductReservationChanged($reservation, 'created');

        if ($loyaltyTransaction !== null) {
            $this->notificationMailer->sendLoyaltyMovement($loyaltyTransaction);
        }

        return $reservation;
    }

    public function confirm(ProductReservation $reservation, User $administrator, string $note = ''): ProductReservation
    {
        $result = $this->entityManager->wrapInTransaction(function () use ($reservation, $note): ProductReservation {
            if (!$reservation->isReserved()) {
                throw new \InvalidArgumentException('Seule une réservation en attente de retrait peut être validée.');
            }

            return $reservation
                ->setAdminNote($note)
                ->confirm();
        });

        $this->notificationMailer->sendProductReservationChanged($result, 'confirmed');

        return $result;
    }

    public function withdraw(ProductReservation $reservation, User $administrator, string $note = ''): ProductReservation
    {
        $result = $this->entityManager->wrapInTransaction(function () use ($reservation, $note): ProductReservation {
            if (!$reservation->isReserved() && !$reservation->isConfirmed()) {
                throw new \InvalidArgumentException('Seule une réservation gardée en magasin peut être marquée comme retirée.');
            }

            $reservation
                ->setAdminNote($note)
                ->withdraw();

            foreach ($reservation->getItems() as $item) {
                $item->getProduct()?->markSold();
            }

            return $reservation;
        });

        $this->notificationMailer->sendProductReservationChanged($result, 'sold');

        return $result;
    }

    public function cancelByCustomer(ProductReservation $reservation, User $customer): ProductReservation
    {
        $loyaltyTransaction = null;
        $result = $this->entityManager->wrapInTransaction(function () use ($reservation, $customer, &$loyaltyTransaction): ProductReservation {
            if ($reservation->getCustomer() !== $customer) {
                throw new \InvalidArgumentException('Cette réservation ne vous appartient pas.');
            }

            if (!$reservation->canBeCancelledByCustomer()) {
                throw new \InvalidArgumentException('Cette réservation ne peut plus être annulée depuis votre espace.');
            }

            $reservation->cancelByCustomer();
            $loyaltyTransaction = $this->loyaltyManager->refundProductReservation(
                $reservation,
                null,
                sprintf('Remboursement fidélité après annulation client de la réservation boutique #%d.', $reservation->getId() ?? 0),
                false,
            );

            return $reservation;
        });

        $this->notificationMailer->sendProductReservationChanged($result, 'cancelled_by_customer');

        if ($loyaltyTransaction !== null) {
            $this->notificationMailer->sendLoyaltyMovement($loyaltyTransaction);
        }

        return $result;
    }

    public function cancelByAdmin(ProductReservation $reservation, User $administrator, string $note = ''): ProductReservation
    {
        $loyaltyTransaction = null;
        $result = $this->entityManager->wrapInTransaction(function () use ($reservation, $administrator, $note, &$loyaltyTransaction): ProductReservation {
            if (!$reservation->isReserved() && !$reservation->isConfirmed()) {
                throw new \InvalidArgumentException('Seule une réservation gardée en magasin peut être remise en vente.');
            }

            $reservation
                ->setAdminNote($note)
                ->cancelByAdmin();
            $loyaltyTransaction = $this->loyaltyManager->refundProductReservation(
                $reservation,
                $administrator,
                sprintf('Remboursement fidélité après annulation admin de la réservation boutique #%d.', $reservation->getId() ?? 0),
                false,
            );

            return $reservation;
        });

        $this->notificationMailer->sendProductReservationChanged($result, 'cancelled_by_admin');

        if ($loyaltyTransaction !== null) {
            $this->notificationMailer->sendLoyaltyMovement($loyaltyTransaction);
        }

        return $result;
    }

    /**
     * @return array{expired: int, refunded_cents: int}
     */
    public function expireOverdueReservations(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $expired = 0;
        $refundedCents = 0;
        $expiredReservations = [];
        $loyaltyTransactions = [];

        $this->entityManager->wrapInTransaction(function () use ($now, &$expired, &$refundedCents, &$expiredReservations, &$loyaltyTransactions): void {
            foreach ($this->reservationRepository->findExpirable($now) as $reservation) {
                $refundable = $reservation->getRefundableLoyaltyCents();
                $reservation->expire();
                $loyaltyTransaction = $this->loyaltyManager->refundProductReservation(
                    $reservation,
                    null,
                    sprintf('Remboursement fidélité après expiration automatique de la réservation boutique #%d.', $reservation->getId() ?? 0),
                    false,
                );

                ++$expired;
                $refundedCents += $refundable;
                $expiredReservations[] = $reservation;

                if ($loyaltyTransaction !== null) {
                    $loyaltyTransactions[] = $loyaltyTransaction;
                }
            }
        });

        foreach ($expiredReservations as $reservation) {
            $this->notificationMailer->sendProductReservationChanged($reservation, 'expired');
        }

        foreach ($loyaltyTransactions as $transaction) {
            $this->notificationMailer->sendLoyaltyMovement($transaction);
        }

        return [
            'expired' => $expired,
            'refunded_cents' => $refundedCents,
        ];
    }

}
