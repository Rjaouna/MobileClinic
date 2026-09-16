<?php

declare(strict_types=1);

namespace App\Controller\Api\Admin;

use App\Entity\Appointment;
use App\Entity\User;
use App\Repository\AppointmentRepository;
use App\Service\AppointmentReminderManager;
use App\Service\LoyaltyManager;
use App\Service\CustomerNotificationMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class AppointmentController extends AbstractController
{
    private const TIMEZONE = 'Europe/Paris';

    public function __construct(
        private readonly AppointmentRepository $appointmentRepository,
        private readonly AppointmentReminderManager $appointmentReminderManager,
        private readonly LoyaltyManager $loyaltyManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly CustomerNotificationMailer $notificationMailer,
    ) {
    }

    #[Route('/api/admin/rendez-vous/rappels', name: 'app_api_admin_appointment_reminders', methods: ['GET'])]
    public function reminders(Request $request): JsonResponse
    {
        $reminderView = $this->appointmentReminderManager->buildAdminView(8);
        $status = (string) $request->query->get('status', '');
        $search = (string) $request->query->get('q', '');

        return new JsonResponse([
            'success' => true,
            'message' => $this->reminderSummaryMessage($reminderView['total']),
            'badge_count' => $reminderView['total'],
            'toast_keys' => $reminderView['toast_keys'],
            'notifications' => $this->serializeReminderItems($reminderView['items']),
            'fragments' => $this->reminderFragments($reminderView, $status, $search),
        ]);
    }

    #[Route('/api/admin/rendez-vous/{id}/statut', name: 'app_api_admin_appointment_status_update', methods: ['POST'])]
    public function updateStatus(Appointment $appointment, Request $request): JsonResponse
    {
        if ($csrfError = $this->csrfError('admin_appointment_status_'.$appointment->getId(), $request)) {
            return $csrfError;
        }

        if (!$request->request->getBoolean('confirm_status')) {
            return $this->validationError('Confirmez la conséquence avant d’enregistrer.');
        }

        $action = (string) $request->request->get('status');
        $admin = $this->getAdmin();
        $adminNote = (string) $request->request->get('admin_note');
        $previousStatus = $appointment->getStatus();
        $previousSchedule = $appointment->getScheduledAt()->format('Y-m-d H:i').':'.$appointment->getDurationMinutes();

        if ($action !== 'reschedule' && !in_array($action, Appointment::ADMIN_ACTION_STATUSES, true)) {
            return $this->validationError('Le statut sélectionné est invalide.');
        }

        $scheduleChange = $this->resolveScheduleChange($appointment, $request, $action);

        if ($scheduleChange instanceof JsonResponse) {
            return $scheduleChange;
        }

        if ($action === 'reschedule') {
            if ($scheduleChange === null) {
                return $this->validationError('Choisissez une nouvelle date ou une nouvelle durée pour reporter le rendez-vous.');
            }

            $this->applyScheduleChange($appointment, $scheduleChange);
            $appointment
                ->markRescheduled()
                ->setStatusChangedBy($admin)
                ->setAdminNote($adminNote);

            $this->appointmentReminderManager->refreshReminders();
            $this->entityManager->flush();
            $this->notificationMailer->sendAppointmentChanged($appointment, 'rescheduled');

            return $this->success($request, 'Le rendez-vous a été reporté.');
        }

        if ($scheduleChange !== null) {
            $this->applyScheduleChange($appointment, $scheduleChange);

            if ($action === Appointment::STATUS_PENDING) {
                $appointment->markRescheduled();
            }
        }

        $appointment
            ->setStatus($action)
            ->setStatusChangedBy($admin)
            ->setAdminNote($adminNote);

        $this->loyaltyManager->syncAppointmentStatus($appointment, $admin);
        $this->appointmentReminderManager->refreshReminders();
        $currentSchedule = $appointment->getScheduledAt()->format('Y-m-d H:i').':'.$appointment->getDurationMinutes();

        if ($previousStatus !== $appointment->getStatus() || $previousSchedule !== $currentSchedule) {
            $this->notificationMailer->sendAppointmentChanged(
                $appointment,
                $previousSchedule !== $currentSchedule ? 'rescheduled' : 'status_changed',
            );
        }

        return $this->success($request, $scheduleChange === null
            ? 'Le statut du rendez-vous a été mis à jour.'
            : 'Le rendez-vous a été mis à jour.'
        );
    }

    /**
     * @return array{scheduledAt: \DateTimeImmutable, durationMinutes: int}|JsonResponse|null
     */
    private function resolveScheduleChange(Appointment $appointment, Request $request, string $action): array|JsonResponse|null
    {
        $rawScheduledAt = trim((string) ($request->request->get('scheduled_at') ?? $request->request->get('rescheduled_at') ?? ''));

        if ($rawScheduledAt === '') {
            return $action === 'reschedule'
                ? $this->validationError('Indiquez une nouvelle date de rendez-vous valide.')
                : null;
        }

        $scheduledAt = $this->parseScheduledAt($rawScheduledAt);

        if (!$scheduledAt instanceof \DateTimeImmutable) {
            return $this->validationError('Indiquez une nouvelle date de rendez-vous valide.');
        }

        $durationMinutes = max(1, (int) $request->request->get('duration_minutes', $appointment->getDurationMinutes()));
        $isSameSchedule = $appointment->getScheduledAt()->format('Y-m-d H:i') === $scheduledAt->format('Y-m-d H:i');
        $isSameDuration = $appointment->getDurationMinutes() === $durationMinutes;

        if ($isSameSchedule && $isSameDuration) {
            return $action === 'reschedule'
                ? $this->validationError('Choisissez une nouvelle date ou une nouvelle durée pour reporter le rendez-vous.')
                : null;
        }

        $targetStatus = $action === 'reschedule' ? Appointment::STATUS_PENDING : $action;

        if (in_array($targetStatus, Appointment::ACTIVE_STATUSES, true)) {
            if ($scheduledAt <= new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE))) {
                return $this->validationError('Le rendez-vous doit être dans le futur.');
            }

            if ($this->appointmentRepository->countActiveAt($scheduledAt, $appointment) > 0) {
                return $this->validationError('Ce créneau est déjà réservé par un autre client.');
            }
        }

        return [
            'scheduledAt' => $scheduledAt,
            'durationMinutes' => $durationMinutes,
        ];
    }

    /**
     * @param array{scheduledAt: \DateTimeImmutable, durationMinutes: int} $scheduleChange
     */
    private function applyScheduleChange(Appointment $appointment, array $scheduleChange): void
    {
        $appointment
            ->setScheduledAt($scheduleChange['scheduledAt'])
            ->setDurationMinutes($scheduleChange['durationMinutes']);
    }

    private function reminderSummaryMessage(int $total): string
    {
        if ($total <= 0) {
            return 'Aucun rendez-vous passé n’attend de décision admin.';
        }

        if ($total === 1) {
            return '1 rendez-vous passé est encore en attente de décision admin.';
        }

        return sprintf('%d rendez-vous passés sont encore en attente de décision admin.', $total);
    }

    private function parseScheduledAt(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        foreach (['!Y-m-d\TH:i', '!Y-m-d H:i'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone(self::TIMEZONE));
            $errors = \DateTimeImmutable::getLastErrors();

            if ($date instanceof \DateTimeImmutable && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $reminderView
     * @return list<array{selector: string, html: string}>
     */
    private function reminderFragments(array $reminderView, string $status = '', string $search = ''): array
    {
        $reminderAppointments = array_map(static fn (array $item): Appointment => $item['appointment'], $reminderView['items']);

        return [
            [
                'selector' => '#admin-appointment-badge',
                'html' => $this->renderView('admin/appointment/partial/_badge.html.twig', [
                    'count' => $reminderView['total'],
                ]),
            ],
            [
                'selector' => '#admin-notification-center',
                'html' => $this->renderView('admin/appointment/partial/_notification_center.html.twig', [
                    'reminder_view' => $reminderView,
                    'status' => $status,
                    'search' => $search,
                ]),
            ],
            [
                'selector' => '#appointment-reminder-modal-stack',
                'html' => $this->renderView('admin/appointment/partial/_modal_stack.html.twig', [
                    'appointments' => $reminderAppointments,
                    'admin_status_choices' => $this->appointmentReminderManager->getAdminStatusChoices(),
                    'status_consequences' => $this->appointmentReminderManager->getStatusConsequences(),
                    'status' => $status,
                    'search' => $search,
                    'stack_id' => 'appointment-reminder-modal-stack',
                ]),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $reminderView
     * @return list<array{selector: string, html: string}>
     */
    private function allFragments(Request $request, array $reminderView): array
    {
        $status = (string) $request->request->get('_list_status', '');
        $search = (string) $request->request->get('_list_search', '');
        $appointments = $this->appointmentRepository->findForAdmin($status, $search);

        return array_merge($this->reminderFragments($reminderView, $status, $search), [
            [
                'selector' => '#appointment-stats-grid',
                'html' => $this->renderView('admin/appointment/partial/_stats.html.twig', [
                    'stats' => $this->appointmentStats($reminderView),
                ]),
            ],
            [
                'selector' => '#appointment-table-body',
                'html' => $this->renderView('admin/appointment/partial/_table_body.html.twig', [
                    'appointments' => $appointments,
                    'status_labels' => Appointment::STATUS_LABELS,
                    'admin_status_choices' => $this->appointmentReminderManager->getAdminStatusChoices(),
                    'status' => $status,
                    'search' => $search,
                ]),
            ],
            [
                'selector' => '#appointment-modal-stack',
                'html' => $this->renderView('admin/appointment/partial/_modal_stack.html.twig', [
                    'appointments' => $appointments,
                    'admin_status_choices' => $this->appointmentReminderManager->getAdminStatusChoices(),
                    'status_consequences' => $this->appointmentReminderManager->getStatusConsequences(),
                    'status' => $status,
                    'search' => $search,
                ]),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $reminderView
     * @return array<string, int>
     */
    private function appointmentStats(array $reminderView): array
    {
        return [
            'pending' => $this->appointmentRepository->countByStatus(Appointment::STATUS_PENDING),
            'confirmed' => $this->appointmentRepository->countByStatus(Appointment::STATUS_CONFIRMED),
            'in_progress' => $this->appointmentRepository->countByStatus(Appointment::STATUS_IN_PROGRESS),
            'upcoming' => $this->appointmentRepository->countUpcoming(),
            'interventions' => (int) $reminderView['total'],
            'no_show' => $this->appointmentRepository->countByStatus(Appointment::STATUS_NO_SHOW),
        ];
    }

    private function success(Request $request, string $message): JsonResponse
    {
        $reminderView = $this->appointmentReminderManager->buildAdminView(8);

        return new JsonResponse([
            'success' => true,
            'message' => $message,
            'badge_count' => $reminderView['total'],
            'toast_keys' => $reminderView['toast_keys'],
            'notifications' => $this->serializeReminderItems($reminderView['items']),
            'fragments' => $this->allFragments($request, $reminderView),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function serializeReminderItems(array $items): array
    {
        return array_map(static fn (array $item): array => [
            'id' => $item['id'],
            'toast_key' => $item['toast_key'],
            'type' => $item['type'],
            'level' => $item['level'],
            'title' => $item['title'],
            'message' => $item['message'],
            'elapsed_label' => $item['elapsed_label'],
        ], $items);
    }

    private function csrfError(string $id, Request $request): ?JsonResponse
    {
        if ($this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            return null;
        }

        return new JsonResponse([
            'success' => false,
            'message' => 'Jeton de sécurité invalide. Rechargez la page avant de réessayer.',
            'errors' => ['Jeton de sécurité invalide. Rechargez la page avant de réessayer.'],
        ], 403);
    }

    private function validationError(string $message): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $message,
            'errors' => [$message],
        ], 422);
    }

    private function getAdmin(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
