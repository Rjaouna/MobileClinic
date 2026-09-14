<?php

declare(strict_types=1);

namespace App\Controller\User;

use App\Entity\Appointment;
use App\Entity\ProductReservation;
use App\Entity\User;
use App\Repository\AppointmentRepository;
use App\Repository\ProductReservationRepository;
use App\Service\AppointmentReminderManager;
use App\Service\AppointmentScheduler;
use App\Service\GeneralSettingManager;
use App\Service\LoyaltyManager;
use App\Service\ProductReservationManager;
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
        private readonly AppointmentReminderManager $appointmentReminderManager,
        private readonly LoyaltyManager $loyaltyManager,
        private readonly ProductReservationRepository $productReservationRepository,
        private readonly ProductReservationManager $productReservationManager,
        private readonly GeneralSettingManager $generalSettingManager,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/espace-client/rendez-vous', name: 'app_user_reservation_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->getCustomer();
        $appointmentData = $request->getSession()->getFlashBag()->get('appointment_data');
        $appointmentErrors = $request->getSession()->getFlashBag()->get('appointment_error');

        return $this->render('user/reservation/index.html.twig', [
            'appointments' => $this->appointmentRepository->findForUser($customer),
            'loyalty' => $this->loyaltyManager->buildAccountView($customer, 4),
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
        $customer = $this->getCustomer();

        return $this->render('user/reservation/show.html.twig', [
            'appointment' => $appointment,
            'loyalty' => $this->loyaltyManager->buildAccountView($customer, 4),
            'slot_days' => $this->appointmentScheduler->getBookableSlotDays($appointment),
        ]);
    }

    #[Route('/espace-client/reservations-boutique', name: 'app_user_product_reservation_index', methods: ['GET'])]
    public function storeReservations(): Response
    {
        $customer = $this->getCustomer();
        $this->productReservationManager->expireOverdueReservations();

        return $this->render('user/reservation/store.html.twig', [
            'customer' => $customer,
            'reservations' => $this->productReservationRepository->findForUser($customer),
            'loyalty' => $this->loyaltyManager->buildAccountView($customer, 4),
            'setting' => $this->generalSettingManager->getSetting(),
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
        $this->loyaltyManager->syncAppointmentStatus($appointment, $this->getCustomer());
        $this->appointmentReminderManager->resolveAppointmentNotifications($appointment);
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

        $this->appointmentReminderManager->resolveAppointmentNotifications($appointment);
        $this->entityManager->flush();
        $this->addFlash('success', 'Votre rendez-vous a été déplacé.');

        return $this->redirectToRoute('app_user_reservation_show', ['id' => $appointment->getId()]);
    }

    #[Route('/espace-client/reservations-boutique/{id}/annuler', name: 'app_user_product_reservation_cancel', methods: ['POST'])]
    public function cancelStoreReservation(ProductReservation $reservation, Request $request): RedirectResponse
    {
        $this->denyUnlessValidCsrf('user_product_reservation_cancel_'.$reservation->getId(), $request);

        try {
            $this->productReservationManager->cancelByCustomer($reservation, $this->getCustomer());
            $this->addFlash('success', 'Votre réservation boutique a été annulée. La fidélité utilisée a été remboursée.');
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_user_product_reservation_index');
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
