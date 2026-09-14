<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Appointment;
use App\Entity\LoyaltyAccount;
use App\Entity\LoyaltySetting;
use App\Entity\LoyaltyTransaction;
use App\Entity\ProductReservation;
use App\Entity\User;
use App\Repository\LoyaltyAccountRepository;
use App\Repository\LoyaltySettingRepository;
use App\Repository\LoyaltyTransactionRepository;
use Doctrine\ORM\EntityManagerInterface;

final class LoyaltyManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoyaltyAccountRepository $accountRepository,
        private readonly LoyaltyTransactionRepository $transactionRepository,
        private readonly LoyaltySettingRepository $settingRepository,
    ) {
    }

    public function getSetting(): LoyaltySetting
    {
        $setting = $this->settingRepository->findCurrent();

        if ($setting instanceof LoyaltySetting) {
            return $setting;
        }

        $setting = new LoyaltySetting();
        $this->entityManager->persist($setting);
        $this->entityManager->flush();

        return $setting;
    }

    public function createPendingAppointmentReward(Appointment $appointment): ?LoyaltyTransaction
    {
        return $this->entityManager->wrapInTransaction(function () use ($appointment): ?LoyaltyTransaction {
            $setting = $this->getSetting();

            if (!$setting->isEnabled() || $setting->getAppointmentRewardCents() <= 0) {
                return null;
            }

            if ($appointment->getId() !== null) {
                $existingReward = $this->transactionRepository->findAppointmentReward($appointment);

                if ($existingReward instanceof LoyaltyTransaction) {
                    return $existingReward;
                }
            }

            $customer = $appointment->getCustomer();

            if (!$customer instanceof User) {
                return null;
            }

            $account = $this->getOrCreateAccount($customer);
            $transaction = (new LoyaltyTransaction())
                ->setAccount($account)
                ->setAppointment($appointment)
                ->setAmountCents($setting->getAppointmentRewardCents())
                ->setType(LoyaltyTransaction::TYPE_GAIN)
                ->setStatus(LoyaltyTransaction::STATUS_PENDING)
                ->setReason('Avantage fidélité créé après une demande de rendez-vous.');

            $this->entityManager->persist($transaction);

            return $transaction;
        });
    }

    public function syncAppointmentStatus(Appointment $appointment, ?User $administrator = null): ?LoyaltyTransaction
    {
        return $this->entityManager->wrapInTransaction(function () use ($appointment, $administrator): ?LoyaltyTransaction {
            $reward = $this->transactionRepository->findAppointmentReward($appointment);

            if (!$reward instanceof LoyaltyTransaction) {
                return null;
            }

            if ($appointment->getStatus() === Appointment::STATUS_COMPLETED) {
                return $this->validatePendingReward($reward, $administrator);
            }

            if (in_array($appointment->getStatus(), Appointment::ACTIVE_STATUSES, true)) {
                return $this->restorePendingReward($reward, $administrator);
            }

            if (in_array($appointment->getStatus(), [
                Appointment::STATUS_CANCELLED_BY_ADMIN,
                Appointment::STATUS_CANCELLED_BY_CLIENT,
                Appointment::STATUS_NO_SHOW,
            ], true)) {
                return $this->cancelPendingReward($reward, $administrator, 'Rendez-vous annulé ou client non présenté.');
            }

            return $reward;
        });
    }

    public function manualCredit(User $customer, int $amountCents, string $reason, User $administrator): LoyaltyTransaction
    {
        return $this->entityManager->wrapInTransaction(function () use ($customer, $amountCents, $reason, $administrator): LoyaltyTransaction {
            $this->assertPositiveAmount($amountCents);
            $reason = $this->sanitizeRequiredReason($reason);
            $account = $this->getOrCreateAccount($customer);

            $transaction = (new LoyaltyTransaction())
                ->setAccount($account)
                ->setAmountCents($amountCents)
                ->setType(LoyaltyTransaction::TYPE_ADJUSTMENT)
                ->setReason($reason)
                ->setAdministrator($administrator)
                ->markValidated();

            $account->addToAvailableBalance($amountCents);
            $this->entityManager->persist($transaction);

            return $transaction;
        });
    }

    public function redeem(User $customer, int $amountCents, string $reason, User $administrator): LoyaltyTransaction
    {
        return $this->entityManager->wrapInTransaction(function () use ($customer, $amountCents, $reason, $administrator): LoyaltyTransaction {
            $this->assertPositiveAmount($amountCents);
            $reason = $this->sanitizeRequiredReason($reason);
            $account = $this->getOrCreateAccount($customer);

            if ($amountCents > $account->getAvailableBalanceCents()) {
                throw new \InvalidArgumentException('Le montant utilisé dépasse la cagnotte disponible.');
            }

            $transaction = (new LoyaltyTransaction())
                ->setAccount($account)
                ->setAmountCents(-$amountCents)
                ->setType(LoyaltyTransaction::TYPE_REDEEM)
                ->setReason($reason)
                ->setAdministrator($administrator)
                ->markValidated();

            $account->addToAvailableBalance(-$amountCents);
            $this->entityManager->persist($transaction);

            return $transaction;
        });
    }

    public function redeemForProductReservation(ProductReservation $reservation, int $amountCents): ?LoyaltyTransaction
    {
        return $this->entityManager->wrapInTransaction(function () use ($reservation, $amountCents): ?LoyaltyTransaction {
            if ($amountCents <= 0) {
                return null;
            }

            $customer = $reservation->getCustomer();

            if (!$customer instanceof User) {
                return null;
            }

            $account = $this->getOrCreateAccount($customer);

            if ($amountCents > $account->getAvailableBalanceCents()) {
                throw new \InvalidArgumentException('Le montant fidélité dépasse la cagnotte disponible.');
            }

            $transaction = (new LoyaltyTransaction())
                ->setAccount($account)
                ->setProductReservation($reservation)
                ->setAmountCents(-$amountCents)
                ->setType(LoyaltyTransaction::TYPE_REDEEM)
                ->setReason(sprintf('Utilisation fidélité sur réservation boutique #%d.', $reservation->getId() ?? 0))
                ->markValidated();

            $account->addToAvailableBalance(-$amountCents);
            $this->entityManager->persist($transaction);

            return $transaction;
        });
    }

    public function refundProductReservation(ProductReservation $reservation, ?User $administrator, string $reason): ?LoyaltyTransaction
    {
        return $this->entityManager->wrapInTransaction(function () use ($reservation, $administrator, $reason): ?LoyaltyTransaction {
            $amountCents = $reservation->getRefundableLoyaltyCents();

            if ($amountCents <= 0) {
                return null;
            }

            $customer = $reservation->getCustomer();

            if (!$customer instanceof User) {
                return null;
            }

            $account = $this->getOrCreateAccount($customer);
            $transaction = (new LoyaltyTransaction())
                ->setAccount($account)
                ->setProductReservation($reservation)
                ->setAmountCents($amountCents)
                ->setType(LoyaltyTransaction::TYPE_ADJUSTMENT)
                ->setReason($this->sanitizeRequiredReason($reason))
                ->setAdministrator($administrator)
                ->markValidated();

            $account->addToAvailableBalance($amountCents);
            $reservation->addLoyaltyRefundedCents($amountCents);
            $this->entityManager->persist($transaction);

            return $transaction;
        });
    }

    public function manualAdjustment(User $customer, int $amountCents, string $reason, User $administrator): LoyaltyTransaction
    {
        return $this->entityManager->wrapInTransaction(function () use ($customer, $amountCents, $reason, $administrator): LoyaltyTransaction {
            if ($amountCents === 0) {
                throw new \InvalidArgumentException('Le montant de correction doit être différent de zéro.');
            }

            $reason = $this->sanitizeRequiredReason($reason);
            $account = $this->getOrCreateAccount($customer);

            if ($amountCents < 0 && abs($amountCents) > $account->getAvailableBalanceCents()) {
                throw new \InvalidArgumentException('La correction ne peut pas rendre la cagnotte négative.');
            }

            $transaction = (new LoyaltyTransaction())
                ->setAccount($account)
                ->setAmountCents($amountCents)
                ->setType(LoyaltyTransaction::TYPE_ADJUSTMENT)
                ->setReason($reason)
                ->setAdministrator($administrator)
                ->markValidated();

            $account->addToAvailableBalance($amountCents);
            $this->entityManager->persist($transaction);

            return $transaction;
        });
    }

    public function cancelPendingTransaction(LoyaltyTransaction $transaction, string $reason, User $administrator): LoyaltyTransaction
    {
        return $this->entityManager->wrapInTransaction(function () use ($transaction, $reason, $administrator): LoyaltyTransaction {
            if (!$transaction->isPending()) {
                throw new \InvalidArgumentException('Seul un mouvement en attente peut être annulé depuis cette action.');
            }

            $reason = $this->sanitizeRequiredReason($reason);
            $transaction
                ->setAdministrator($administrator)
                ->setReason($transaction->getReason().' Annulation : '.$reason)
                ->markCancelled();

            return $transaction;
        });
    }

    public function updateSetting(int $appointmentRewardCents, bool $isEnabled): LoyaltySetting
    {
        return $this->entityManager->wrapInTransaction(function () use ($appointmentRewardCents, $isEnabled): LoyaltySetting {
            $setting = $this->getSetting()
                ->setAppointmentRewardCents($appointmentRewardCents)
                ->setIsEnabled($isEnabled);

            return $setting;
        });
    }

    /**
     * @return array{
     *     account: LoyaltyAccount|null,
     *     available_balance_cents: int,
     *     pending_balance_cents: int,
     *     validated_gains_cents: int,
     *     cancelled_gains_cents: int,
     *     redeemed_cents: int,
     *     recent_transactions: list<LoyaltyTransaction>,
     *     transactions: list<LoyaltyTransaction>
     * }
     */
    public function buildAccountView(User $customer, int $recentLimit = 5): array
    {
        $account = $this->accountRepository->findOneByCustomer($customer);

        if (!$account instanceof LoyaltyAccount) {
            return [
                'account' => null,
                'available_balance_cents' => 0,
                'pending_balance_cents' => 0,
                'validated_gains_cents' => 0,
                'cancelled_gains_cents' => 0,
                'redeemed_cents' => 0,
                'recent_transactions' => [],
                'transactions' => [],
            ];
        }

        $transactions = $this->transactionRepository->findForAccount($account);
        $validatedGains = 0;
        $cancelledGains = 0;
        $redeemed = 0;

        foreach ($transactions as $transaction) {
            if ($transaction->getType() === LoyaltyTransaction::TYPE_GAIN && $transaction->isValidated()) {
                $validatedGains += $transaction->getAmountCents();
            }

            if ($transaction->getType() === LoyaltyTransaction::TYPE_GAIN && $transaction->getStatus() === LoyaltyTransaction::STATUS_CANCELLED) {
                $cancelledGains += $transaction->getAmountCents();
            }

            if ($transaction->getType() === LoyaltyTransaction::TYPE_REDEEM && $transaction->isValidated()) {
                $redeemed += $transaction->getAbsoluteAmountCents();
            }
        }

        return [
            'account' => $account,
            'available_balance_cents' => $account->getAvailableBalanceCents(),
            'pending_balance_cents' => $this->transactionRepository->sumPendingForAccount($account),
            'validated_gains_cents' => $validatedGains,
            'cancelled_gains_cents' => $cancelledGains,
            'redeemed_cents' => $redeemed,
            'recent_transactions' => $this->transactionRepository->findRecentForAccount($account, $recentLimit),
            'transactions' => $transactions,
        ];
    }

    public function getOrCreateAccount(User $customer): LoyaltyAccount
    {
        $account = $this->accountRepository->findOneByCustomer($customer);

        if ($account instanceof LoyaltyAccount) {
            return $account;
        }

        $account = (new LoyaltyAccount())->setCustomer($customer);
        $this->entityManager->persist($account);

        return $account;
    }

    private function validatePendingReward(LoyaltyTransaction $reward, ?User $administrator): LoyaltyTransaction
    {
        if (!$reward->isPending()) {
            return $reward;
        }

        $reward->setAdministrator($administrator)->markValidated();
        $reward->getAccount()?->addToAvailableBalance($reward->getAmountCents());

        return $reward;
    }

    private function restorePendingReward(LoyaltyTransaction $reward, ?User $administrator): LoyaltyTransaction
    {
        if ($reward->isPending()) {
            return $reward;
        }

        if ($reward->isValidated()) {
            $reward->getAccount()?->addToAvailableBalance(-$reward->getAmountCents());
        }

        return $reward
            ->setAdministrator($administrator)
            ->markPending();
    }

    private function cancelPendingReward(LoyaltyTransaction $reward, ?User $administrator, string $reason): LoyaltyTransaction
    {
        if (!$reward->isPending()) {
            return $reward;
        }

        return $reward
            ->setAdministrator($administrator)
            ->setReason($reward->getReason().' Annulation : '.$reason)
            ->markCancelled();
    }

    private function assertPositiveAmount(int $amountCents): void
    {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('Le montant doit être positif.');
        }
    }

    private function sanitizeRequiredReason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new \InvalidArgumentException('Un motif est obligatoire.');
        }

        return $reason;
    }
}
