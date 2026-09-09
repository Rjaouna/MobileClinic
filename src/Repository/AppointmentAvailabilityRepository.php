<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AppointmentAvailability;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AppointmentAvailability>
 */
final class AppointmentAvailabilityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppointmentAvailability::class);
    }

    /** @return list<AppointmentAvailability> */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('availability')
            ->addOrderBy('availability.dayOfWeek', 'ASC')
            ->addOrderBy('availability.startTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<AppointmentAvailability> */
    public function findEnabledOrdered(): array
    {
        return $this->createQueryBuilder('availability')
            ->andWhere('availability.isEnabled = :enabled')
            ->setParameter('enabled', true)
            ->addOrderBy('availability.dayOfWeek', 'ASC')
            ->addOrderBy('availability.startTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<AppointmentAvailability> */
    public function findByDayOrdered(int $dayOfWeek): array
    {
        return $this->createQueryBuilder('availability')
            ->andWhere('availability.dayOfWeek = :dayOfWeek')
            ->setParameter('dayOfWeek', $dayOfWeek)
            ->addOrderBy('availability.startTime', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
