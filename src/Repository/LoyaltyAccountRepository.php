<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LoyaltyAccount;
use App\Entity\LoyaltyTransaction;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LoyaltyAccount>
 */
final class LoyaltyAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LoyaltyAccount::class);
    }

    public function findOneByCustomer(User $customer): ?LoyaltyAccount
    {
        return $this->findOneBy(['customer' => $customer]);
    }

    public function countAccounts(): int
    {
        return (int) $this->createQueryBuilder('account')
            ->select('COUNT(account.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array{
     *     available_balance_cents: int,
     *     pending_balance_cents: int,
     *     validated_gains_cents: int,
     *     cancelled_gains_cents: int,
     *     redeemed_cents: int
     * }
     */
    public function getTotals(): array
    {
        $availableBalance = (int) $this->createQueryBuilder('account')
            ->select('COALESCE(SUM(account.availableBalanceCents), 0)')
            ->getQuery()
            ->getSingleScalarResult();

        $connection = $this->getEntityManager()->getConnection();
        $totals = $connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    COALESCE(SUM(CASE WHEN status = :pending THEN amount_cents ELSE 0 END), 0) AS pending_balance_cents,
                    COALESCE(SUM(CASE WHEN status = :validated AND type = :gain THEN amount_cents ELSE 0 END), 0) AS validated_gains_cents,
                    COALESCE(SUM(CASE WHEN status = :cancelled AND type = :gain THEN amount_cents ELSE 0 END), 0) AS cancelled_gains_cents,
                    COALESCE(SUM(CASE WHEN status = :validated AND type = :redeem THEN ABS(amount_cents) ELSE 0 END), 0) AS redeemed_cents
                FROM loyalty_transaction
            SQL,
            [
                'pending' => LoyaltyTransaction::STATUS_PENDING,
                'validated' => LoyaltyTransaction::STATUS_VALIDATED,
                'cancelled' => LoyaltyTransaction::STATUS_CANCELLED,
                'gain' => LoyaltyTransaction::TYPE_GAIN,
                'redeem' => LoyaltyTransaction::TYPE_REDEEM,
            ],
        ) ?: [];

        return [
            'available_balance_cents' => $availableBalance,
            'pending_balance_cents' => (int) ($totals['pending_balance_cents'] ?? 0),
            'validated_gains_cents' => (int) ($totals['validated_gains_cents'] ?? 0),
            'cancelled_gains_cents' => (int) ($totals['cancelled_gains_cents'] ?? 0),
            'redeemed_cents' => (int) ($totals['redeemed_cents'] ?? 0),
        ];
    }
}
