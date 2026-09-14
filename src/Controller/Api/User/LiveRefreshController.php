<?php

declare(strict_types=1);

namespace App\Controller\Api\User;

use App\Entity\Appointment;
use App\Entity\User;
use App\Repository\AppointmentRepository;
use App\Repository\ProductReservationRepository;
use App\Service\AppointmentScheduler;
use App\Service\GeneralSettingManager;
use App\Service\LoyaltyManager;
use App\Service\ProductReservationManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class LiveRefreshController extends AbstractController
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

    #[Route('/api/espace-client/actualisation', name: 'app_api_user_live_refresh', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $customer = $this->getCustomer();
        $this->productReservationManager->expireOverdueReservations();
        $appointments = $this->appointmentRepository->findForUser($customer);
        $productReservations = $this->productReservationRepository->findForUser($customer);
        $loyaltySummary = $this->loyaltyManager->buildAccountView($customer, 4);
        $loyaltyHistory = $this->loyaltyManager->buildAccountView($customer, 8);
        $slotDays = $this->appointmentScheduler->getBookableSlotDays();
        $generalSetting = $this->generalSettingManager->getSetting();

        $fragments = [
            $this->fragment('#user-dashboard-account-line', 'user/partial/_account_line.html.twig', [
                'id' => 'user-dashboard-account-line',
                'customer' => $customer,
            ]),
            $this->fragment('#user-loyalty-account-line', 'user/partial/_account_line.html.twig', [
                'id' => 'user-loyalty-account-line',
                'customer' => $customer,
            ]),
            $this->fragment('#user-profile-account-line', 'user/partial/_account_line.html.twig', [
                'id' => 'user-profile-account-line',
                'customer' => $customer,
            ]),
            $this->fragment('#user-store-account-line', 'user/partial/_account_line.html.twig', [
                'id' => 'user-store-account-line',
                'customer' => $customer,
            ]),
            $this->fragment('#user-dashboard-overview', 'user/dashboard/partial/_overview.html.twig', [
                'customer' => $customer,
                'appointments' => $appointments,
                'next_appointment' => $appointments[0] ?? null,
            ]),
            $this->fragment('#user-dashboard-loyalty', 'components/_loyalty_summary.html.twig', [
                'id' => 'user-dashboard-loyalty',
                'title' => 'Ma cagnotte fidélité',
                'loyalty' => $loyaltySummary,
                'href' => $this->generateUrl('app_user_loyalty_index'),
            ]),
            $this->fragment('#user-dashboard-store-reservations', 'user/dashboard/partial/_store_reservations.html.twig', [
                'reservations' => $productReservations,
                'setting' => $generalSetting,
            ]),
            $this->fragment('#user-reservation-loyalty', 'components/_loyalty_summary.html.twig', [
                'id' => 'user-reservation-loyalty',
                'title' => 'Ma carte de fidélité',
                'loyalty' => $loyaltySummary,
                'href' => $this->generateUrl('app_user_loyalty_index'),
            ]),
            $this->fragment('#user-reservation-show-loyalty', 'components/_loyalty_summary.html.twig', [
                'id' => 'user-reservation-show-loyalty',
                'title' => 'Ma carte de fidélité',
                'loyalty' => $loyaltySummary,
                'href' => $this->generateUrl('app_user_loyalty_index'),
            ]),
            $this->fragment('#user-loyalty-card', 'components/_loyalty_summary.html.twig', [
                'id' => 'user-loyalty-card',
                'title' => 'Ma cagnotte Mobile Clinic',
                'loyalty' => $loyaltyHistory,
            ]),
            $this->fragment('#user-store-loyalty', 'components/_loyalty_summary.html.twig', [
                'id' => 'user-store-loyalty',
                'title' => 'Ma carte de fidélité',
                'loyalty' => $loyaltySummary,
                'href' => $this->generateUrl('app_user_loyalty_index'),
            ]),
            $this->fragment('#user-loyalty-history', 'user/loyalty/partial/_history.html.twig', [
                'loyalty' => $loyaltyHistory,
            ]),
            $this->fragment('#user-reservation-list', 'user/reservation/partial/_list.html.twig', [
                'appointments' => $appointments,
            ]),
            $this->fragment('#user-store-reservation-list', 'user/reservation/partial/_store_list.html.twig', [
                'reservations' => $productReservations,
                'setting' => $generalSetting,
            ]),
            $this->fragment('#user-reservation-modal-stack', 'user/reservation/partial/_modal_stack.html.twig', [
                'appointments' => $appointments,
                'slot_days' => $slotDays,
            ], true),
            $this->fragment('#user-profile-summary', 'user/profile/partial/_summary.html.twig', [
                'customer' => $customer,
            ]),
        ];

        $appointment = $this->requestedAppointment($request, $appointments);

        if ($appointment instanceof Appointment) {
            $fragments[] = $this->fragment('#user-reservation-show-heading', 'user/reservation/partial/_show_heading.html.twig', [
                'appointment' => $appointment,
            ]);
            $fragments[] = $this->fragment('#user-reservation-detail-'.$appointment->getId(), 'user/reservation/partial/_show_detail.html.twig', [
                'appointment' => $appointment,
            ]);
            $fragments[] = $this->fragment('#user-reservation-show-modal-stack', 'user/reservation/partial/_show_modal_stack.html.twig', [
                'appointment' => $appointment,
                'slot_days' => $this->appointmentScheduler->getBookableSlotDays($appointment),
            ], true);
        }

        return new JsonResponse([
            'success' => true,
            'fragments' => $fragments,
            'refreshed_at' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     * @return array{selector: string, html: string, skip_when_modal_open?: bool}
     */
    private function fragment(string $selector, string $template, array $context, bool $skipWhenModalOpen = false): array
    {
        $fragment = [
            'selector' => $selector,
            'html' => $this->renderView($template, $context),
        ];

        if ($skipWhenModalOpen) {
            $fragment['skip_when_modal_open'] = true;
        }

        return $fragment;
    }

    /**
     * @param list<Appointment> $appointments
     */
    private function requestedAppointment(Request $request, array $appointments): ?Appointment
    {
        $appointmentId = max(0, (int) $request->query->get('appointment_id', 0));

        if ($appointmentId <= 0) {
            return null;
        }

        foreach ($appointments as $appointment) {
            if ($appointment->getId() === $appointmentId) {
                return $appointment;
            }
        }

        return null;
    }

    private function getCustomer(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
