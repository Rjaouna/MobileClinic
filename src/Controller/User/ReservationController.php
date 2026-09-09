<?php

declare(strict_types=1);

namespace App\Controller\User;

use App\Entity\Appointment;
use App\Entity\User;
use App\Repository\AppointmentRepository;
use App\Service\AppointmentScheduler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ReservationController extends AbstractController
{
    public function __construct(
        private readonly AppointmentRepository $appointmentRepository,
        private readonly AppointmentScheduler $appointmentScheduler,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/espace-client/rendez-vous', name: 'app_user_reservation_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->appointmentScheduler->syncExpiredAppointments();
        $appointmentData = $request->getSession()->getFlashBag()->get('appointment_data');
        $appointmentErrors = $request->getSession()->getFlashBag()->get('appointment_error');

        return $this->render('user/reservation/index.html.twig', [
            'appointments' => $this->appointmentRepository->findForUser($this->getCustomer()),
            'devices' => $this->appointmentScheduler->getDeviceChoices(),
            'problems' => $this->appointmentScheduler->getProblemChoices(),
            'slot_days' => $this->appointmentScheduler->getBookableSlotDays(),
            'appointment_data' => $appointmentData[0] ?? [],
            'appointment_errors' => $appointmentErrors,
            'open_modal' => $appointmentErrors !== [] ? 'appointment-modal' : $request->query->get('modal'),
        ]);
    }

    #[Route('/espace-client/rendez-vous/{id}', name: 'app_user_reservation_show', methods: ['GET'])]
    public function show(Appointment $appointment): Response
    {
        $this->denyUnlessOwner($appointment);

        return $this->render('user/reservation/show.html.twig', [
            'appointment' => $appointment,
            'slot_days' => $this->appointmentScheduler->getBookableSlotDays($appointment),
        ]);
    }

    #[Route('/espace-client/rendez-vous/{id}/annuler', name: 'app_user_reservation_cancel', methods: ['POST'])]
    public function cancel(Appointment $appointment, Request $request): RedirectResponse
    {
        $this->denyUnlessOwner($appointment);
        $this->denyUnlessValidCsrf('user_reservation_cancel_'.$appointment->getId(), $request);

        if (!$appointment->canBeChangedByCustomer()) {
            $this->addFlash('error', 'Ce rendez-vous ne peut plus être annulé depuis votre espace.');

            return $this->redirectToRoute('app_user_reservation_index');
        }

        $appointment->setStatus(Appointment::STATUS_CANCELLED_BY_CLIENT);
        $this->entityManager->flush();
        $this->addFlash('success', 'Votre rendez-vous a été annulé.');

        return $this->redirectToRoute('app_user_reservation_index');
    }

    #[Route('/espace-client/rendez-vous/{id}/deplacer', name: 'app_user_reservation_reschedule', methods: ['POST'])]
    public function reschedule(Appointment $appointment, Request $request): RedirectResponse
    {
        $this->denyUnlessOwner($appointment);
        $this->denyUnlessValidCsrf('user_reservation_reschedule_'.$appointment->getId(), $request);

        if (!$appointment->canBeChangedByCustomer()) {
            $this->addFlash('error', 'Ce rendez-vous ne peut plus être déplacé depuis votre espace.');

            return $this->redirectToRoute('app_user_reservation_show', ['id' => $appointment->getId()]);
        }

        $slot = $this->appointmentScheduler->findBookableSlotData((string) $request->request->get('slot'), $appointment);

        if ($slot === null) {
            $this->addFlash('error', 'Ce créneau n’est plus disponible.');

            return $this->redirectToRoute('app_user_reservation_show', ['id' => $appointment->getId()]);
        }

        $appointment
            ->setScheduledAt($this->appointmentScheduler->parseSlotValue($slot['value']))
            ->setDurationMinutes($slot['duration_minutes'])
            ->markRescheduled();

        $this->entityManager->flush();
        $this->addFlash('success', 'Votre rendez-vous a été déplacé.');

        return $this->redirectToRoute('app_user_reservation_show', ['id' => $appointment->getId()]);
    }

    private function getCustomer(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function denyUnlessOwner(Appointment $appointment): void
    {
        if ($appointment->getCustomer() !== $this->getCustomer()) {
            throw $this->createAccessDeniedException('Ce rendez-vous ne vous appartient pas.');
        }
    }

    private function denyUnlessValidCsrf(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }
}
