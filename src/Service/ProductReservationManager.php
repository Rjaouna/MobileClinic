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
        return $this->entityManager->wrapInTransaction(function () use ($customer, $productIds, $useLoyalty): ProductReservation {
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
            $this->loyaltyManager->redeemForProductReservation($reservation, $reservation->getLoyaltyUsedCents());

            return $reservation;
        });
    }

    public function confirm(ProductReservation $reservation, User $administrator, string $note = ''): ProductReservation
    {
        return $this->entityManager->wrapInTransaction(function () use ($reservation, $note): ProductReservation {
            if (!$reservation->isReserved()) {
                throw new \InvalidArgumentException('Seule une réservation en attente de retrait peut être validée.');
            }

            return $reservation
                ->setAdminNote($note)
                ->confirm();
        });
    }

    public function withdraw(ProductReservation $reservation, User $administrator, string $note = ''): ProductReservation
    {
        return $this->entityManager->wrapInTransaction(function () use ($reservation, $note): ProductReservation {
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
    }

    public function cancelByCustomer(ProductReservation $reservation, User $customer): ProductReservation
    {
        return $this->entityManager->wrapInTransaction(function () use ($reservation, $customer): ProductReservation {
            if ($reservation->getCustomer() !== $customer) {
                throw new \InvalidArgumentException('Cette réservation ne vous appartient pas.');
            }

            if (!$reservation->canBeCancelledByCustomer()) {
                throw new \InvalidArgumentException('Cette réservation ne peut plus être annulée depuis votre espace.');
            }

            $reservation->cancelByCustomer();
            $this->loyaltyManager->refundProductReservation(
                $reservation,
                null,
                sprintf('Remboursement fidélité après annulation client de la réservation boutique #%d.', $reservation->getId() ?? 0),
            );

            return $reservation;
        });
    }

    public function cancelByAdmin(ProductReservation $reservation, User $administrator, string $note = ''): ProductReservation
    {
        return $this->entityManager->wrapInTransaction(function () use ($reservation, $administrator, $note): ProductReservation {
            if (!$reservation->isReserved()) {
                throw new \InvalidArgumentException('Seule une réservation en attente de retrait peut être annulée.');
            }

            $reservation
                ->setAdminNote($note)
                ->cancelByAdmin();
            $this->loyaltyManager->refundProductReservation(
                $reservation,
                $administrator,
                sprintf('Remboursement fidélité après annulation admin de la réservation boutique #%d.', $reservation->getId() ?? 0),
            );

            return $reservation;
        });
    }

    /**
     * @return array{expired: int, refunded_cents: int}
     */
    public function expireOverdueReservations(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $expired = 0;
        $refundedCents = 0;

        $this->entityManager->wrapInTransaction(function () use ($now, &$expired, &$refundedCents): void {
            foreach ($this->reservationRepository->findExpirable($now) as $reservation) {
                $refundable = $reservation->getRefundableLoyaltyCents();
                $reservation->expire();
                $this->loyaltyManager->refundProductReservation(
                    $reservation,
                    null,
                    sprintf('Remboursement fidélité après expiration automatique de la réservation boutique #%d.', $reservation->getId() ?? 0),
                );

                ++$expired;
                $refundedCents += $refundable;
            }
        });

        return [
            'expired' => $expired,
            'refunded_cents' => $refundedCents,
        ];
    }
}
