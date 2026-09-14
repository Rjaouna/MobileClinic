<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Appointment;
use App\Entity\AppointmentNotification;
use App\Entity\AppointmentSetting;
use App\Repository\AppointmentNotificationRepository;
use App\Repository\AppointmentRepository;
use App\Repository\AppointmentSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final class AppointmentReminderManager
{
    private const TIMEZONE = 'Europe/Paris';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AppointmentRepository $appointmentRepository,
        private readonly AppointmentNotificationRepository $notificationRepository,
        private readonly AppointmentSettingRepository $settingRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array{created: int, updated: int, resolved: int, active: int}
     */
    public function refreshReminders(): array
    {
        return $this->entityManager->wrapInTransaction(function (): array {
            $now = $this->now();
            $setting = $this->getSetting();
            $created = 0;
            $updated = 0;
            $resolved = 0;

            if ($setting->isRemindersEnabled()) {
                $deadline = $now->modify(sprintf('-%d minutes', $setting->getFirstReminderDelayMinutes()));
                $candidates = $this->appointmentRepository->findReminderCandidates($deadline);
                $notificationsByKey = $this->notificationRepository->findByUniqueKeys(array_map(
                    fn (Appointment $appointment): string => $this->uniqueKey($appointment),
                    $candidates,
                ));

                foreach ($candidates as $appointment) {
                    $uniqueKey = $this->uniqueKey($appointment);
                    $result = $this->createOrUpdateReminder($appointment, $setting, $now, $notificationsByKey[$uniqueKey] ?? null);
                    $created += $result === 'created' ? 1 : 0;
                    $updated += $result === 'updated' ? 1 : 0;
                }
            }

            foreach ($this->notificationRepository->findActiveAppointmentReminders() as $notification) {
                $appointment = $notification->getAppointment();

                if (!$appointment instanceof Appointment || !$this->shouldStayActive($appointment, $setting, $now)) {
                    $notification->resolve($now);
                    ++$resolved;
                }
            }

            $this->entityManager->flush();

            return [
                'created' => $created,
                'updated' => $updated,
                'resolved' => $resolved,
                'active' => $this->notificationRepository->countActiveAppointmentReminders(),
            ];
        });
    }

    public function resolveAppointmentNotifications(Appointment $appointment): int
    {
        $resolved = 0;
        $now = $this->now();

        foreach ($this->notificationRepository->findActiveAppointmentReminders() as $notification) {
            if ($notification->getAppointment()?->getId() !== $appointment->getId()) {
                continue;
            }

            $notification->resolve($now);
            ++$resolved;
        }

        if ($resolved > 0) {
            $this->entityManager->flush();
        }

        return $resolved;
    }

    /**
     * @return array{
     *     total: int,
     *     has_items: bool,
     *     toast_keys: list<string>,
     *     items: list<array{
     *         id: int|null,
     *         toast_key: string,
     *         unique_key: string,
     *         type: string,
     *         type_label: string,
     *         level: string,
     *         level_variant: string,
     *         title: string,
     *         message: string,
     *         appointment: Appointment,
     *         customer_name: string,
     *         phone: string,
     *         scheduled_label: string,
     *         time_label: string,
     *         elapsed_minutes: int,
     *         elapsed_label: string,
     *         status_label: string,
     *         status_variant: string,
     *         modal_id: string,
     *         quick_status: string|null
     *     }>
     * }
     */
    public function buildAdminView(int $limit = 8): array
    {
        $this->refreshReminders();

        $now = $this->now();
        $notifications = $this->notificationRepository->findActiveAppointmentReminders();
        usort($notifications, static function (AppointmentNotification $left, AppointmentNotification $right): int {
            $priority = [
                AppointmentNotification::TYPE_APPOINTMENT_OVERDUE => 0,
                AppointmentNotification::TYPE_APPOINTMENT_DUE => 1,
            ];
            $leftPriority = $priority[$left->getType()] ?? 9;
            $rightPriority = $priority[$right->getType()] ?? 9;

            if ($leftPriority !== $rightPriority) {
                return $leftPriority <=> $rightPriority;
            }

            return ($left->getAppointment()?->getScheduledAt()?->getTimestamp() ?? 0)
                <=> ($right->getAppointment()?->getScheduledAt()?->getTimestamp() ?? 0);
        });

        $items = array_values(array_filter(array_map(
            fn (AppointmentNotification $notification): ?array => $this->buildItem($notification, $now),
            array_slice($notifications, 0, max(1, $limit)),
        )));

        return [
            'total' => count($notifications),
            'has_items' => $items !== [],
            'toast_keys' => array_map(static fn (array $item): string => $item['toast_key'], $items),
            'items' => $items,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getAdminStatusChoices(): array
    {
        $choices = [];

        foreach (Appointment::ADMIN_ACTION_STATUSES as $status) {
            $choices[$status] = Appointment::STATUS_LABELS[$status];
        }

        $choices['reschedule'] = 'Reporter le rendez-vous';

        return $choices;
    }

    /**
     * @return array<string, string>
     */
    public function getStatusConsequences(): array
    {
        return Appointment::STATUS_CONSEQUENCES + [
            'reschedule' => 'Le rendez-vous garde le même avantage fidélité en attente. Aucun nouveau gain ne sera créé.',
        ];
    }

    private function createOrUpdateReminder(
        Appointment $appointment,
        AppointmentSetting $setting,
        \DateTimeImmutable $now,
        ?AppointmentNotification $notification,
    ): string
    {
        if ($appointment->getId() === null) {
            return 'none';
        }

        $scheduledAt = $appointment->getScheduledAt();

        if (!$scheduledAt instanceof \DateTimeImmutable) {
            return 'none';
        }

        $elapsedMinutes = $this->elapsedMinutes($scheduledAt, $now);
        $isOverdue = $elapsedMinutes >= $setting->getPriorityReminderDelayMinutes();
        $type = $isOverdue
            ? AppointmentNotification::TYPE_APPOINTMENT_OVERDUE
            : AppointmentNotification::TYPE_APPOINTMENT_DUE;
        $level = $isOverdue
            ? AppointmentNotification::LEVEL_URGENT
            : AppointmentNotification::LEVEL_ATTENTION;
        $uniqueKey = $this->uniqueKey($appointment);
        $customer = $appointment->getCustomer();
        $customerName = $customer?->getDisplayName() ?: $appointment->getEmail();
        $scheduledTime = $this->appointmentTimeInStoreTimezone($scheduledAt)->format('H:i');
        $title = AppointmentNotification::TYPE_LABELS[$type];
        $message = $isOverdue
            ? sprintf(
                'Le rendez-vous de %s, prévu à %s, est dépassé depuis plus d’une heure. Modifiez son statut afin de finaliser le dossier et de calculer correctement la fidélité du client.',
                $customerName,
                $scheduledTime,
            )
            : sprintf(
                'Le rendez-vous de %s, prévu à %s, doit être pris en charge. Merci d’indiquer si le client est arrivé.',
                $customerName,
                $scheduledTime,
            );

        if (!$notification instanceof AppointmentNotification) {
            $notification = (new AppointmentNotification())
                ->setUniqueKey($uniqueKey)
                ->setAppointment($appointment);
            $this->entityManager->persist($notification);
            $state = 'created';
        } else {
            $state = 'none';
        }

        if (!$notification->isActive()) {
            $notification->reactivate();
            $state = $state === 'created' ? 'created' : 'updated';
        }

        if (
            $notification->getType() !== $type
            || $notification->getLevel() !== $level
            || $notification->getTitle() !== $title
            || $notification->getMessage() !== $message
        ) {
            $notification
                ->setType($type)
                ->setLevel($level)
                ->setTitle($title)
                ->setMessage($message);
            $state = $state === 'created' ? 'created' : 'updated';
        }

        return $state;
    }

    private function shouldStayActive(Appointment $appointment, AppointmentSetting $setting, \DateTimeImmutable $now): bool
    {
        if (!$setting->isRemindersEnabled() || !$appointment->needsAdminDecision()) {
            return false;
        }

        $scheduledAt = $appointment->getScheduledAt();

        return $scheduledAt instanceof \DateTimeImmutable && $this->appointmentTimeInStoreTimezone($scheduledAt) <= $now;
    }

    private function buildItem(AppointmentNotification $notification, \DateTimeImmutable $now): ?array
    {
        $appointment = $notification->getAppointment();
        $scheduledAt = $appointment?->getScheduledAt();

        if (!$appointment instanceof Appointment || !$scheduledAt instanceof \DateTimeImmutable) {
            return null;
        }

        $customer = $appointment->getCustomer();
        $elapsedMinutes = $this->elapsedMinutes($scheduledAt, $now);

        return [
            'id' => $notification->getId(),
            'toast_key' => sprintf('%s:%s', $notification->getId() ?? $notification->getUniqueKey(), $notification->getType()),
            'unique_key' => $notification->getUniqueKey(),
            'type' => $notification->getType(),
            'type_label' => AppointmentNotification::TYPE_LABELS[$notification->getType()],
            'level' => $notification->getLevel(),
            'level_variant' => $notification->getLevelVariant(),
            'title' => $notification->getTitle(),
            'message' => $notification->getMessage(),
            'appointment' => $appointment,
            'customer_name' => $customer?->getDisplayName() ?: $appointment->getEmail(),
            'phone' => $appointment->getPhone() ?: $customer?->getPhone() ?: 'Téléphone non renseigné',
            'scheduled_label' => $this->formatStoreDate($scheduledAt),
            'time_label' => $this->appointmentTimeInStoreTimezone($scheduledAt)->format('H:i'),
            'elapsed_minutes' => $elapsedMinutes,
            'elapsed_label' => $this->formatElapsed($elapsedMinutes),
            'status_label' => $appointment->getStatusLabel(),
            'status_variant' => $appointment->getStatusVariant(),
            'modal_id' => 'appointment-status-'.$appointment->getId(),
            'quick_status' => isset(Appointment::STATUS_LABELS[Appointment::STATUS_CONFIRMED]) ? Appointment::STATUS_CONFIRMED : null,
        ];
    }

    private function getSetting(): AppointmentSetting
    {
        $setting = $this->settingRepository->findCurrent();

        if ($setting instanceof AppointmentSetting) {
            return $setting;
        }

        $setting = new AppointmentSetting();
        $this->entityManager->persist($setting);

        return $setting;
    }

    private function uniqueKey(Appointment $appointment): string
    {
        return 'appointment-reminder-'.$appointment->getId();
    }

    private function elapsedMinutes(\DateTimeImmutable $scheduledAt, \DateTimeImmutable $now): int
    {
        return max(0, intdiv($now->getTimestamp() - $this->appointmentTimeInStoreTimezone($scheduledAt)->getTimestamp(), 60));
    }

    private function formatElapsed(int $minutes): string
    {
        if ($minutes < 60) {
            return sprintf('Rendez-vous prévu il y a %d minute%s', $minutes, $minutes > 1 ? 's' : '');
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        if ($remainingMinutes === 0) {
            return sprintf('En attente depuis %d h', $hours);
        }

        return sprintf('En attente depuis %d h %02d', $hours, $remainingMinutes);
    }

    private function formatStoreDate(\DateTimeImmutable $date): string
    {
        return $this->appointmentTimeInStoreTimezone($date)->format('d/m/Y H:i');
    }

    private function now(): \DateTimeImmutable
    {
        return $this->toStoreTimezone($this->clock->now());
    }

    private function toStoreTimezone(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->setTimezone(new \DateTimeZone(self::TIMEZONE));
    }

    private function appointmentTimeInStoreTimezone(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable(
            $date->format('Y-m-d H:i:s'),
            new \DateTimeZone(self::TIMEZONE),
        );
    }
}
