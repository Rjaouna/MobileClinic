<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CustomerCheckIn;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CustomerCheckIn>
 */
final class CustomerCheckInRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomerCheckIn::class);
    }

    public function findUnresolvedForCustomer(User $customer): ?CustomerCheckIn
    {
        return $this->createQueryBuilder('checkIn')
            ->andWhere('checkIn.customer = :customer')
            ->andWhere('checkIn.resolvedAt IS NULL')
            ->setParameter('customer', $customer)
            ->orderBy('checkIn.lastScannedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<CustomerCheckIn> */
    public function findUnresolved(int $limit = 20): array
    {
        return $this->createQueryBuilder('checkIn')
            ->addSelect('customer')
            ->join('checkIn.customer', 'customer')
            ->andWhere('checkIn.resolvedAt IS NULL')
            ->orderBy('checkIn.lastScannedAt', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    public function countUnresolved(): int
    {
        return (int) $this->createQueryBuilder('checkIn')
            ->select('COUNT(checkIn.id)')
            ->andWhere('checkIn.resolvedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
