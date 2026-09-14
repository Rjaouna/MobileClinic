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
    public function findForAdmin(?string $status = null, string $search = ''): array
    {
        $qb = $this->createQueryBuilder('appointment')
            ->addSelect('customer')
            ->leftJoin('appointment.customer', 'customer')
            ->addOrderBy('appointment.scheduledAt', 'DESC')
            ->addOrderBy('appointment.id', 'DESC');

        if ($status !== null && isset(Appointment::STATUS_LABELS[$status])) {
            $qb
                ->andWhere('appointment.status = :status')
                ->setParameter('status', $status);
        }

        $search = mb_strtolower(trim($search));

        if ($search !== '') {
            $qb
                ->andWhere(
                    'LOWER(appointment.email) LIKE :search
                    OR appointment.phone LIKE :search
                    OR LOWER(appointment.device) LIKE :search
                    OR LOWER(appointment.problem) LIKE :search
                    OR LOWER(customer.email) LIKE :search
                    OR customer.phone LIKE :search
                    OR LOWER(customer.firstName) LIKE :search
                    OR LOWER(customer.lastName) LIKE :search',
                )
                ->setParameter('search', '%'.$search.'%');
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

    /** @return list<Appointment> */
    public function findReminderCandidates(\DateTimeImmutable $deadline): array
    {
        return $this->createQueryBuilder('appointment')
            ->addSelect('customer')
            ->join('appointment.customer', 'customer')
            ->andWhere('appointment.status IN (:statuses)')
            ->andWhere('appointment.scheduledAt <= :deadline')
            ->setParameter('statuses', Appointment::ADMIN_DECISION_REQUIRED_STATUSES)
            ->setParameter('deadline', $deadline)
            ->addOrderBy('appointment.scheduledAt', 'ASC')
            ->addOrderBy('appointment.id', 'ASC')
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

    /** @param list<string> $statuses */
    public function countByStatuses(array $statuses): int
    {
        if ($statuses === []) {
            return 0;
        }

        return (int) $this->createQueryBuilder('appointment')
            ->select('COUNT(appointment.id)')
            ->andWhere('appointment.status IN (:statuses)')
            ->setParameter('statuses', $statuses)
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
