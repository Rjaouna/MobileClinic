<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProductReservation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductReservation>
 */
final class ProductReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductReservation::class);
    }

    /** @return list<ProductReservation> */
    public function findForAdmin(string $status = '', string $search = ''): array
    {
        $qb = $this->createQueryBuilder('reservation')
            ->addSelect('customer', 'item', 'product')
            ->join('reservation.customer', 'customer')
            ->leftJoin('reservation.items', 'item')
            ->leftJoin('item.product', 'product')
            ->addOrderBy('reservation.createdAt', 'DESC')
            ->addOrderBy('reservation.id', 'DESC');

        if (isset(ProductReservation::STATUS_LABELS[$status])) {
            $qb
                ->andWhere('reservation.status = :status')
                ->setParameter('status', $status);
        }

        $search = mb_strtolower(trim($search));

        if ($search !== '') {
            $qb
                ->andWhere('LOWER(customer.email) LIKE :search OR customer.phone LIKE :search OR LOWER(customer.firstName) LIKE :search OR LOWER(customer.lastName) LIKE :search OR LOWER(item.productName) LIKE :search')
                ->setParameter('search', '%'.$search.'%');
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<ProductReservation> */
    public function findForUser(User $customer): array
    {
        return $this->createQueryBuilder('reservation')
            ->addSelect('item', 'product')
            ->leftJoin('reservation.items', 'item')
            ->leftJoin('item.product', 'product')
            ->andWhere('reservation.customer = :customer')
            ->setParameter('customer', $customer)
            ->addOrderBy('reservation.createdAt', 'DESC')
            ->addOrderBy('reservation.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<ProductReservation> */
    public function findExpirable(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('reservation')
            ->addSelect('customer', 'item')
            ->join('reservation.customer', 'customer')
            ->leftJoin('reservation.items', 'item')
            ->andWhere('reservation.status = :status')
            ->andWhere('reservation.expiresAt IS NOT NULL')
            ->andWhere('reservation.expiresAt <= :now')
            ->setParameter('status', ProductReservation::STATUS_RESERVED)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    public function countByStatus(string $status): int
    {
        return (int) $this->createQueryBuilder('reservation')
            ->select('COUNT(reservation.id)')
            ->andWhere('reservation.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @param list<string> $statuses */
    public function countByStatuses(array $statuses): int
    {
        if ($statuses === []) {
            return 0;
        }

        return (int) $this->createQueryBuilder('reservation')
            ->select('COUNT(reservation.id)')
            ->andWhere('reservation.status IN (:statuses)')
            ->setParameter('statuses', $statuses)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
