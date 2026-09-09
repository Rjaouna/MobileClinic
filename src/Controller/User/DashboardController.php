<?php

declare(strict_types=1);

namespace App\Controller\User;

use App\Entity\User;
use App\Repository\AppointmentRepository;
use App\Service\AppointmentScheduler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly AppointmentRepository $appointmentRepository,
        private readonly AppointmentScheduler $appointmentScheduler,
    ) {
    }

    #[Route('/espace-client', name: 'app_user_dashboard', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->appointmentScheduler->syncExpiredAppointments();
        $user = $this->getUser();
        $appointments = $user instanceof User ? $this->appointmentRepository->findForUser($user) : [];
        $appointmentData = $request->getSession()->getFlashBag()->get('appointment_data');
        $appointmentErrors = $request->getSession()->getFlashBag()->get('appointment_error');

        return $this->render('user/dashboard/index.html.twig', [
            'user_email' => $this->getUser()?->getUserIdentifier(),
            'appointments' => $appointments,
            'next_appointment' => $appointments[0] ?? null,
            'devices' => $this->appointmentScheduler->getDeviceChoices(),
            'problems' => $this->appointmentScheduler->getProblemChoices(),
            'slot_days' => $this->appointmentScheduler->getBookableSlotDays(),
            'appointment_data' => $appointmentData[0] ?? [],
            'appointment_errors' => $appointmentErrors,
            'open_modal' => $appointmentErrors !== [] ? 'appointment-modal' : $request->query->get('modal'),
        ]);
    }
}
