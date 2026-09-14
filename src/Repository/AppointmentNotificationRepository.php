<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Appointment;
use App\Entity\AppointmentNotification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AppointmentNotification>
 */
final class AppointmentNotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppointmentNotification::class);
    }

    public function findActiveAppointmentReminderFor(Appointment $appointment): ?AppointmentNotification
    {
        return $this->createQueryBuilder('notification')
            ->andWhere('notification.appointment = :appointment')
            ->andWhere('notification.status = :status')
            ->andWhere('notification.type IN (:types)')
            ->setParameter('appointment', $appointment)
            ->setParameter('status', AppointmentNotification::STATUS_ACTIVE)
            ->setParameter('types', [
                AppointmentNotification::TYPE_APPOINTMENT_DUE,
                AppointmentNotification::TYPE_APPOINTMENT_OVERDUE,
            ])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByUniqueKey(string $uniqueKey): ?AppointmentNotification
    {
        return $this->findOneBy(['uniqueKey' => $uniqueKey]);
    }

    /**
     * @param list<string> $uniqueKeys
     * @return array<string, AppointmentNotification>
     */
    public function findByUniqueKeys(array $uniqueKeys): array
    {
        $uniqueKeys = array_values(array_unique(array_filter($uniqueKeys)));

        if ($uniqueKeys === []) {
            return [];
        }

        $notifications = $this->createQueryBuilder('notification')
            ->andWhere('notification.uniqueKey IN (:uniqueKeys)')
            ->setParameter('uniqueKeys', $uniqueKeys)
            ->getQuery()
            ->getResult();

        $indexed = [];

        foreach ($notifications as $notification) {
            $indexed[$notification->getUniqueKey()] = $notification;
        }

        return $indexed;
    }

    /**
     * @return list<AppointmentNotification>
     */
    public function findActiveAppointmentReminders(): array
    {
        return $this->createQueryBuilder('notification')
            ->addSelect('appointment')
            ->addSelect('customer')
            ->join('notification.appointment', 'appointment')
            ->join('appointment.customer', 'customer')
            ->andWhere('notification.status = :status')
            ->andWhere('notification.type IN (:types)')
            ->setParameter('status', AppointmentNotification::STATUS_ACTIVE)
            ->setParameter('types', [
                AppointmentNotification::TYPE_APPOINTMENT_DUE,
                AppointmentNotification::TYPE_APPOINTMENT_OVERDUE,
            ])
            ->getQuery()
            ->getResult();
    }

    public function countActiveAppointmentReminders(): int
    {
        return (int) $this->createQueryBuilder('notification')
            ->select('COUNT(notification.id)')
            ->andWhere('notification.status = :status')
            ->andWhere('notification.type IN (:types)')
            ->setParameter('status', AppointmentNotification::STATUS_ACTIVE)
            ->setParameter('types', [
                AppointmentNotification::TYPE_APPOINTMENT_DUE,
                AppointmentNotification::TYPE_APPOINTMENT_OVERDUE,
            ])
            ->getQuery()
            ->getSingleScalarResult();
    }
}
