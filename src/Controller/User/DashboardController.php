<?php

declare(strict_types=1);

namespace App\Controller\User;

use App\Entity\User;
use App\Repository\AppointmentRepository;
use App\Repository\ProductReservationRepository;
use App\Service\AppointmentScheduler;
use App\Service\GeneralSettingManager;
use App\Service\LoyaltyManager;
use App\Service\ProductReservationManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly AppointmentRepository $appointmentRepository,
        private readonly AppointmentScheduler $appointmentScheduler,
        private readonly LoyaltyManager $loyaltyManager,
        private readonly ProductReservationRepository $productReservationRepository,
        private readonly ProductReservationManager $productReservationManager,
        private readonly GeneralSettingManager $generalSettingManager,
    ) {
    }

    #[Route('/espace-client', name: 'app_user_dashboard', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $this->productReservationManager->expireOverdueReservations();
        $appointments = $this->appointmentRepository->findForUser($user);
        $productReservations = $this->productReservationRepository->findForUser($user);
        $appointmentData = $request->getSession()->getFlashBag()->get('appointment_data');
        $appointmentErrors = $request->getSession()->getFlashBag()->get('appointment_error');

        return $this->render('user/dashboard/index.html.twig', [
            'customer' => $user,
            'user_email' => $user->getUserIdentifier(),
            'appointments' => $appointments,
            'next_appointment' => $appointments[0] ?? null,
            'product_reservations' => $productReservations,
            'setting' => $this->generalSettingManager->getSetting(),
            'loyalty' => $this->loyaltyManager->buildAccountView($user, 4),
            'devices' => $this->appointmentScheduler->getDeviceChoices(),
            'problems' => $this->appointmentScheduler->getProblemChoices(),
            'slot_days' => $this->appointmentScheduler->getBookableSlotDays(),
            'appointment_data' => $appointmentData[0] ?? [],
            'appointment_errors' => $appointmentErrors,
            'open_modal' => $appointmentErrors !== [] ? 'appointment-modal' : $request->query->get('modal'),
        ]);
    }
}
