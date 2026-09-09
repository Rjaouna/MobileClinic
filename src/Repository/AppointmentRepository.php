<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Appointment;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Appointment>
 */
final class AppointmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Appointment::class);
    }

    /** @return list<Appointment> */
    public function findForAdmin(?string $status = null): array
    {
        $qb = $this->createQueryBuilder('appointment')
            ->addOrderBy('appointment.scheduledAt', 'DESC')
            ->addOrderBy('appointment.id', 'DESC');

        if ($status !== null && isset(Appointment::STATUS_LABELS[$status])) {
            $qb
                ->andWhere('appointment.status = :status')
                ->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<Appointment> */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('appointment')
            ->andWhere('appointment.customer = :customer')
            ->setParameter('customer', $user)
            ->addOrderBy('appointment.scheduledAt', 'DESC')
            ->addOrderBy('appointment.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Appointment> */
    public function findNoShowCandidates(\DateTimeImmutable $deadline): array
    {
        return $this->createQueryBuilder('appointment')
            ->andWhere('appointment.status IN (:statuses)')
            ->andWhere('appointment.scheduledAt <= :deadline')
            ->setParameter('statuses', Appointment::ACTIVE_STATUSES)
            ->setParameter('deadline', $deadline)
            ->getQuery()
            ->getResult();
    }

    public function countActiveAt(\DateTimeImmutable $scheduledAt, ?Appointment $excludedAppointment = null): int
    {
        $qb = $this->createQueryBuilder('appointment')
            ->select('COUNT(appointment.id)')
            ->andWhere('appointment.scheduledAt = :scheduledAt')
            ->andWhere('appointment.status IN (:statuses)')
            ->setParameter('scheduledAt', $scheduledAt)
            ->setParameter('statuses', Appointment::ACTIVE_STATUSES);

        if ($excludedAppointment?->getId() !== null) {
            $qb
                ->andWhere('appointment.id != :excludedAppointment')
                ->setParameter('excludedAppointment', $excludedAppointment->getId());
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function phoneExistsForAnotherCustomer(string $phone, ?User $allowedCustomer = null): bool
    {
        $qb = $this->createQueryBuilder('appointment')
            ->select('COUNT(DISTINCT customer.id)')
            ->join('appointment.customer', 'customer')
            ->andWhere('appointment.phone = :phone')
            ->setParameter('phone', $phone);

        if ($allowedCustomer?->getId() !== null) {
            $qb
                ->andWhere('customer.id != :customerId')
                ->setParameter('customerId', $allowedCustomer->getId());
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /** @return list<\DateTimeImmutable> */
    public function findActiveScheduledAtBetween(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        ?Appointment $excludedAppointment = null,
    ): array {
        $qb = $this->createQueryBuilder('appointment')
            ->select('appointment.scheduledAt')
            ->andWhere('appointment.status IN (:statuses)')
            ->andWhere('appointment.scheduledAt >= :start')
            ->andWhere('appointment.scheduledAt <= :end')
            ->setParameter('statuses', Appointment::ACTIVE_STATUSES)
            ->setParameter('start', $start)
            ->setParameter('end', $end);

        if ($excludedAppointment?->getId() !== null) {
            $qb
                ->andWhere('appointment.id != :excludedAppointment')
                ->setParameter('excludedAppointment', $excludedAppointment->getId());
        }

        return array_map(
            static fn (array $row): \DateTimeImmutable => $row['scheduledAt'],
            $qb->getQuery()->getArrayResult(),
        );
    }

    public function countByStatus(string $status): int
    {
        return (int) $this->createQueryBuilder('appointment')
            ->select('COUNT(appointment.id)')
            ->andWhere('appointment.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUpcoming(): int
    {
        return (int) $this->createQueryBuilder('appointment')
            ->select('COUNT(appointment.id)')
            ->andWhere('appointment.status IN (:statuses)')
            ->andWhere('appointment.scheduledAt >= :now')
            ->setParameter('statuses', Appointment::ACTIVE_STATUSES)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();
    }
}
