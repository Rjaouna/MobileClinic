<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Appointment;
use App\Entity\AppointmentAvailability;
use App\Repository\AppointmentRepository;
use App\Service\AppointmentScheduler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class AppointmentController extends AbstractController
{
    public function __construct(
        private readonly AppointmentScheduler $appointmentScheduler,
        private readonly AppointmentRepository $appointmentRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/admin/rendez-vous', name: 'app_admin_appointment_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->appointmentScheduler->syncExpiredAppointments();

        $status = $request->query->get('status');

        return $this->render('admin/appointment/index.html.twig', [
            'appointments' => $this->appointmentRepository->findForAdmin(is_string($status) ? $status : null),
            'week_days' => $this->appointmentScheduler->getAvailabilityWeekOverview(),
            'setting' => $this->appointmentScheduler->getSetting(),
            'days' => $this->appointmentScheduler->getDays(),
            'status' => is_string($status) ? $status : '',
            'status_labels' => Appointment::STATUS_LABELS,
            'status_variants' => Appointment::STATUS_VARIANTS,
            'stats' => [
                'pending' => $this->appointmentRepository->countByStatus(Appointment::STATUS_PENDING),
                'confirmed' => $this->appointmentRepository->countByStatus(Appointment::STATUS_CONFIRMED),
                'upcoming' => $this->appointmentRepository->countUpcoming(),
                'no_show' => $this->appointmentRepository->countByStatus(Appointment::STATUS_NO_SHOW),
            ],
        ]);
    }

    #[Route('/admin/rendez-vous/jours/{day}', name: 'app_admin_appointment_day', requirements: ['day' => '[1-7]'], methods: ['GET'])]
    public function day(int $day, Request $request): Response
    {
        $this->appointmentScheduler->syncExpiredAppointments();
        $status = $request->query->get('status');

        return $this->render('admin/appointment/index.html.twig', [
            'appointments' => $this->appointmentRepository->findForAdmin(is_string($status) ? $status : null),
            'week_days' => $this->appointmentScheduler->getAvailabilityWeekOverview(),
            'setting' => $this->appointmentScheduler->getSetting(),
            'days' => $this->appointmentScheduler->getDays(),
            'status' => is_string($status) ? $status : '',
            'status_labels' => Appointment::STATUS_LABELS,
            'status_variants' => Appointment::STATUS_VARIANTS,
            'stats' => [
                'pending' => $this->appointmentRepository->countByStatus(Appointment::STATUS_PENDING),
                'confirmed' => $this->appointmentRepository->countByStatus(Appointment::STATUS_CONFIRMED),
                'upcoming' => $this->appointmentRepository->countUpcoming(),
                'no_show' => $this->appointmentRepository->countByStatus(Appointment::STATUS_NO_SHOW),
            ],
            'selected_day' => $day,
            'selected_day_label' => $this->appointmentScheduler->getDays()[$day],
            'selected_day_summary' => $this->appointmentScheduler->getAvailabilityDayOverview($day),
            'selected_day_availabilities' => $this->appointmentScheduler->getAvailabilitiesForDay($day),
        ]);
    }

    #[Route('/admin/rendez-vous/reglages', name: 'app_admin_appointment_settings_update', methods: ['POST'])]
    public function updateSettings(Request $request): RedirectResponse
    {
        $this->denyUnlessValidCsrf('admin_appointment_settings', $request);

        $this->appointmentScheduler->getSetting()
            ->setNoShowDelayMinutes((int) $request->request->get('no_show_delay_minutes', 60))
            ->setBookingWindowDays((int) $request->request->get('booking_window_days', 21))
            ->setDefaultSlotDurationMinutes((int) $request->request->get('default_slot_duration_minutes', 30));

        $this->entityManager->flush();
        $this->addFlash('success', 'Les réglages des rendez-vous ont été mis à jour.');

        return $this->redirectToRoute('app_admin_appointment_index');
    }

    #[Route('/admin/rendez-vous/plages', name: 'app_admin_appointment_availability_create', methods: ['POST'])]
    public function createAvailability(Request $request): RedirectResponse
    {
        $this->denyUnlessValidCsrf('admin_appointment_availability_create', $request);

        $dayOfWeek = (int) $request->request->get('day_of_week');

        if (!isset(AppointmentAvailability::DAYS[$dayOfWeek])) {
            $this->addFlash('error', 'Le jour sélectionné est invalide.');

            return $this->redirectAfterAvailabilityAction($request);
        }

        try {
            $startTime = $this->parseTime((string) $request->request->get('start_time'));
            $endTime = $this->parseTime((string) $request->request->get('end_time'));
        } catch (\InvalidArgumentException) {
            $this->addFlash('error', 'Les heures renseignées sont invalides.');

            return $this->redirectAfterAvailabilityAction($request, $dayOfWeek);
        }

        $availability = (new AppointmentAvailability())
            ->setDayOfWeek($dayOfWeek)
            ->setStartTime($startTime)
            ->setEndTime($endTime)
            ->setSlotDurationMinutes((int) $request->request->get('slot_duration_minutes', 30))
            ->setIsEnabled($request->request->has('is_enabled'));

        if ($availability->getStartTime() >= $availability->getEndTime()) {
            $this->addFlash('error', 'L’heure de fin doit être après l’heure de début.');

            return $this->redirectAfterAvailabilityAction($request, $dayOfWeek);
        }

        $this->entityManager->persist($availability);
        $this->entityManager->flush();
        $this->addFlash('success', 'La plage horaire a été ajoutée.');

        return $this->redirectAfterAvailabilityAction($request, $dayOfWeek);
    }

    #[Route('/admin/rendez-vous/jours/{day}/basculer', name: 'app_admin_appointment_day_toggle', requirements: ['day' => '[1-7]'], methods: ['POST'])]
    public function toggleDay(int $day, Request $request): RedirectResponse
    {
        $this->denyUnlessValidCsrf('admin_appointment_day_'.$day, $request);

        $availabilities = $this->appointmentScheduler->getAvailabilitiesForDay($day);

        if ($availabilities === []) {
            $this->addFlash('error', 'Ajoutez d’abord une plage horaire pour gérer la visibilité de cette journée.');

            return $this->redirectToRoute('app_admin_appointment_day', ['day' => $day]);
        }

        $shouldEnable = true;

        foreach ($availabilities as $availability) {
            if ($availability->isEnabled()) {
                $shouldEnable = false;
                break;
            }
        }

        foreach ($availabilities as $availability) {
            $availability->setIsEnabled($shouldEnable);
        }

        $this->entityManager->flush();
        $this->addFlash('success', sprintf('La journée %s est maintenant %s.', mb_strtolower(AppointmentAvailability::DAYS[$day]), $shouldEnable ? 'visible' : 'masquée'));

        return $this->redirectAfterAvailabilityAction($request);
    }

    #[Route('/admin/rendez-vous/plages/{id}/basculer', name: 'app_admin_appointment_availability_toggle', methods: ['POST'])]
    public function toggleAvailability(AppointmentAvailability $availability, Request $request): RedirectResponse
    {
        $this->denyUnlessValidCsrf('admin_appointment_availability_'.$availability->getId(), $request);

        $availability->setIsEnabled(!$availability->isEnabled());
        $this->entityManager->flush();

        return $this->redirectAfterAvailabilityAction($request, $availability->getDayOfWeek());
    }

    #[Route('/admin/rendez-vous/plages/{id}/supprimer', name: 'app_admin_appointment_availability_delete', methods: ['POST'])]
    public function deleteAvailability(AppointmentAvailability $availability, Request $request): RedirectResponse
    {
        $this->denyUnlessValidCsrf('admin_appointment_availability_'.$availability->getId(), $request);

        $this->entityManager->remove($availability);
        $this->entityManager->flush();
        $this->addFlash('success', 'La plage horaire a été supprimée.');

        return $this->redirectAfterAvailabilityAction($request, $availability->getDayOfWeek());
    }

    #[Route('/admin/rendez-vous/{id}/statut', name: 'app_admin_appointment_status_update', methods: ['POST'])]
    public function updateStatus(Appointment $appointment, Request $request): RedirectResponse
    {
        $this->denyUnlessValidCsrf('admin_appointment_status_'.$appointment->getId(), $request);

        $status = (string) $request->request->get('status');

        if (!isset(Appointment::STATUS_LABELS[$status])) {
            $this->addFlash('error', 'Le statut sélectionné est invalide.');

            return $this->redirectToRoute('app_admin_appointment_index');
        }

        $appointment
            ->setStatus($status)
            ->setAdminNote((string) $request->request->get('admin_note'));

        $this->entityManager->flush();
        $this->addFlash('success', 'Le statut du rendez-vous a été mis à jour.');

        return $this->redirectToRoute('app_admin_appointment_index');
    }

    #[Route('/admin/rendez-vous/synchroniser', name: 'app_admin_appointment_sync_expired', methods: ['POST'])]
    public function syncExpired(Request $request): RedirectResponse
    {
        $this->denyUnlessValidCsrf('admin_appointment_sync', $request);

        $count = $this->appointmentScheduler->syncExpiredAppointments();
        $this->addFlash('success', sprintf('%d rendez-vous passé(s) ont été contrôlés.', $count));

        return $this->redirectToRoute('app_admin_appointment_index');
    }

    private function redirectAfterAvailabilityAction(Request $request, ?int $fallbackDay = null): RedirectResponse
    {
        $day = (int) $request->request->get('_redirect_day', $fallbackDay ?? 0);

        if (isset(AppointmentAvailability::DAYS[$day])) {
            return $this->redirectToRoute('app_admin_appointment_day', ['day' => $day]);
        }

        return $this->redirectToRoute('app_admin_appointment_index');
    }

    private function parseTime(string $value): \DateTimeImmutable
    {
        $time = \DateTimeImmutable::createFromFormat('!H:i', $value, new \DateTimeZone('Europe/Paris'));

        if (!$time instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException('Heure invalide.');
        }

        return $time;
    }

    private function denyUnlessValidCsrf(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }
}
