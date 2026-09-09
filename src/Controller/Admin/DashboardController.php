<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Appointment;
use App\Repository\AppointmentRepository;
use App\Service\AppointmentScheduler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly AppointmentRepository $appointmentRepository,
        private readonly AppointmentScheduler $appointmentScheduler,
    ) {
    }

    #[Route('/admin', name: 'app_admin_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $this->appointmentScheduler->syncExpiredAppointments();

        return $this->render('admin/dashboard/index.html.twig', [
            'user_email' => $this->getUser()?->getUserIdentifier(),
            'stats' => [
                'appointments' => $this->appointmentRepository->countUpcoming(),
                'pending' => $this->appointmentRepository->countByStatus(Appointment::STATUS_PENDING),
                'no_show' => $this->appointmentRepository->countByStatus(Appointment::STATUS_NO_SHOW),
            ],
        ]);
    }
}
