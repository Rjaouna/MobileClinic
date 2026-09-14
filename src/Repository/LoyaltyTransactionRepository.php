<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Appointment;
use App\Entity\LoyaltyAccount;
use App\Entity\LoyaltyTransaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LoyaltyTransaction>
 */
final class LoyaltyTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LoyaltyTransaction::class);
    }

    public function findAppointmentReward(Appointment $appointment): ?LoyaltyTransaction
    {
        return $this->findOneBy([
            'appointment' => $appointment,
            'type' => LoyaltyTransaction::TYPE_GAIN,
        ]);
    }

    /** @return list<LoyaltyTransaction> */
    public function findRecentForAccount(LoyaltyAccount $account, int $limit = 5): array
    {
        return $this->createQueryBuilder('transaction')
            ->andWhere('transaction.account = :account')
            ->setParameter('account', $account)
            ->addOrderBy('transaction.createdAt', 'DESC')
            ->addOrderBy('transaction.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<LoyaltyTransaction> */
    public function findForAccount(LoyaltyAccount $account): array
    {
        return $this->createQueryBuilder('transaction')
            ->andWhere('transaction.account = :account')
            ->setParameter('account', $account)
            ->addOrderBy('transaction.createdAt', 'DESC')
            ->addOrderBy('transaction.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function sumPendingForAccount(LoyaltyAccount $account): int
    {
        return (int) $this->createQueryBuilder('transaction')
            ->select('COALESCE(SUM(transaction.amountCents), 0)')
            ->andWhere('transaction.account = :account')
            ->andWhere('transaction.status = :pending')
            ->setParameter('account', $account)
            ->setParameter('pending', LoyaltyTransaction::STATUS_PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function hasHistoryForAccount(LoyaltyAccount $account): bool
    {
        return (int) $this->createQueryBuilder('transaction')
            ->select('COUNT(transaction.id)')
            ->andWhere('transaction.account = :account')
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
