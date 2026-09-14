<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use App\Entity\ProductReservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
final class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /** @return list<Product> */
    public function findForStore(string $search = ''): array
    {
        $qb = $this->createQueryBuilder('product')
            ->andWhere('product.isActive = :active')
            ->andWhere('product.soldAt IS NULL')
            ->setParameter('active', true)
            ->addOrderBy('product.createdAt', 'DESC')
            ->addOrderBy('product.id', 'DESC');

        $search = mb_strtolower(trim($search));

        if ($search !== '') {
            $qb
                ->andWhere('LOWER(product.name) LIKE :search OR LOWER(product.description) LIKE :search')
                ->setParameter('search', '%'.$search.'%');
        }

        return $qb->getQuery()
            ->getResult();
    }

    /** @return list<Product> */
    public function findForAdmin(string $search = '', string $filter = ''): array
    {
        $qb = $this->createQueryBuilder('product')
            ->addOrderBy('product.createdAt', 'DESC')
            ->addOrderBy('product.id', 'DESC');

        $search = mb_strtolower(trim($search));

        if ($search !== '') {
            $qb
                ->andWhere('LOWER(product.name) LIKE :search OR LOWER(product.description) LIKE :search')
                ->setParameter('search', '%'.$search.'%');
        }

        if ($filter === 'active') {
            $qb
                ->andWhere('product.isActive = :active')
                ->andWhere('product.soldAt IS NULL')
                ->setParameter('active', true);
        }

        if ($filter === 'inactive') {
            $qb
                ->andWhere('product.isActive = :active')
                ->andWhere('product.soldAt IS NULL')
                ->setParameter('active', false);
        }

        if ($filter === 'sold') {
            $qb->andWhere('product.soldAt IS NOT NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /** @param list<int> $ids */
    public function findActiveByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $products = $this->createQueryBuilder('product')
            ->andWhere('product.id IN (:ids)')
            ->andWhere('product.isActive = :active')
            ->andWhere('product.soldAt IS NULL')
            ->setParameter('ids', $ids)
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();

        $indexed = [];

        foreach ($products as $product) {
            $indexed[$product->getId()] = $product;
        }

        return array_values(array_filter(array_map(
            static fn (int $id): ?Product => $indexed[$id] ?? null,
            $ids,
        )));
    }

    /** @return list<int> */
    public function findBlockedProductIds(): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT product.id AS id')
            ->from(ProductReservation::class, 'reservation')
            ->join('reservation.items', 'item')
            ->join('item.product', 'product')
            ->andWhere('reservation.status IN (:statuses)')
            ->setParameter('statuses', ProductReservation::ACTIVE_STATUSES)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    public function isBlocked(Product $product, ?ProductReservation $excludedReservation = null): bool
    {
        if ($product->getId() === null) {
            return false;
        }

        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(reservation.id)')
            ->from(ProductReservation::class, 'reservation')
            ->join('reservation.items', 'item')
            ->andWhere('item.product = :product')
            ->andWhere('reservation.status IN (:statuses)')
            ->setParameter('product', $product)
            ->setParameter('statuses', ProductReservation::ACTIVE_STATUSES);

        if ($excludedReservation?->getId() !== null) {
            $qb
                ->andWhere('reservation.id != :reservationId')
                ->setParameter('reservationId', $excludedReservation->getId());
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function countSold(): int
    {
        return (int) $this->createQueryBuilder('product')
            ->select('COUNT(product.id)')
            ->andWhere('product.soldAt IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
