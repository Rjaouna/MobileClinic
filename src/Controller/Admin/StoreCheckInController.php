<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CustomerCheckIn;
use App\Entity\User;
use App\Service\AppointmentReminderManager;
use App\Service\StoreCheckInManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class StoreCheckInController extends AbstractController
{
    public function __construct(
        private readonly StoreCheckInManager $checkInManager,
        private readonly AppointmentReminderManager $appointmentReminderManager,
    ) {
    }

    #[Route('/admin/presences-magasin/{id}/ouvrir-fidelite', name: 'app_admin_store_check_in_open_loyalty', methods: ['POST'])]
    public function openLoyalty(CustomerCheckIn $checkIn, Request $request): RedirectResponse
    {
        $this->denyUnlessValidCsrf($checkIn, $request);
        $customer = $checkIn->getCustomer();

        if (!$customer instanceof User) {
            throw $this->createNotFoundException('Le compte client lié à cette présence est introuvable.');
        }

        $this->checkInManager->resolve($checkIn, $this->administrator(), CustomerCheckIn::RESOLUTION_LOYALTY_OPENED);
        $this->addFlash('success', sprintf('Présence traitée. La cagnotte de %s est ouverte.', $customer->getDisplayName()));

        return $this->redirectToRoute('app_admin_loyalty_show', ['id' => $customer->getId()]);
    }

    #[Route('/api/admin/presences-magasin/{id}/classer', name: 'app_api_admin_store_check_in_dismiss', methods: ['POST'])]
    public function dismiss(CustomerCheckIn $checkIn, Request $request): JsonResponse
    {
        $this->denyUnlessValidCsrf($checkIn, $request);
        $this->checkInManager->resolve($checkIn, $this->administrator(), CustomerCheckIn::RESOLUTION_DISMISSED);

        return $this->notificationPayload($request, 'La notification de présence a été classée.');
    }

    private function notificationPayload(Request $request, string $message): JsonResponse
    {
        $reminderView = $this->appointmentReminderManager->buildAdminView(8);
        $checkInView = $this->checkInManager->buildAdminView(8);
        $status = (string) $request->request->get('_list_status', '');
        $search = (string) $request->request->get('_list_search', '');
        $toastKeys = array_values(array_merge($checkInView['toast_keys'], $reminderView['toast_keys']));
        $reminderAppointments = array_map(static fn (array $item) => $item['appointment'], $reminderView['items']);

        return new JsonResponse([
            'success' => true,
            'message' => $message,
            'badge_count' => $reminderView['total'],
            'toast_keys' => $toastKeys,
            'fragments' => [
                [
                    'selector' => '#admin-notification-center',
                    'html' => $this->renderView('admin/appointment/partial/_notification_center.html.twig', [
                        'reminder_view' => $reminderView,
                        'check_in_view' => $checkInView,
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
            ],
        ]);
    }

    private function denyUnlessValidCsrf(CustomerCheckIn $checkIn, Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_store_check_in_'.$checkIn->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }

    private function administrator(): User
    {
        $administrator = $this->getUser();

        if (!$administrator instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $administrator;
    }
}
