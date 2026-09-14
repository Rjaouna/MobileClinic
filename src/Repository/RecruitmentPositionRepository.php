<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RecruitmentPosition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecruitmentPosition>
 */
final class RecruitmentPositionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecruitmentPosition::class);
    }

    /** @return list<RecruitmentPosition> */
    public function findActivePositions(): array
    {
        return $this->createQueryBuilder('position')
            ->andWhere('position.isActive = :active')
            ->setParameter('active', true)
            ->addOrderBy('position.createdAt', 'ASC')
            ->addOrderBy('position.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<RecruitmentPosition> */
    public function findForAdmin(): array
    {
        return $this->createQueryBuilder('position')
            ->addOrderBy('position.isActive', 'DESC')
            ->addOrderBy('position.createdAt', 'DESC')
            ->addOrderBy('position.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findActive(int $id): ?RecruitmentPosition
    {
        return $this->findOneBy(['id' => $id, 'isActive' => true]);
    }

    public function countActive(): int
    {
        return (int) $this->createQueryBuilder('position')
            ->select('COUNT(position.id)')
            ->andWhere('position.isActive = :active')
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function titleExists(string $title, ?int $excludedId = null): bool
    {
        $qb = $this->createQueryBuilder('position')
            ->select('COUNT(position.id)')
            ->andWhere('LOWER(position.title) = :title')
            ->setParameter('title', mb_strtolower(trim($title)));

        if ($excludedId !== null) {
            $qb
                ->andWhere('position.id != :excludedId')
                ->setParameter('excludedId', $excludedId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
