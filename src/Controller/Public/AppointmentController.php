<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\Appointment;
use App\Entity\User;
use App\Repository\AppointmentRepository;
use App\Repository\UserRepository;
use App\Service\AppointmentScheduler;
use App\Service\CustomerAccountFactory;
use App\Service\LoyaltyManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;

final class AppointmentController extends AbstractController
{
    public function __construct(
        private readonly AppointmentScheduler $appointmentScheduler,
        private readonly CustomerAccountFactory $customerAccountFactory,
        private readonly AppointmentRepository $appointmentRepository,
        private readonly UserRepository $userRepository,
        private readonly LoyaltyManager $loyaltyManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    #[Route('/rendez-vous/telephone/verifier', name: 'app_public_appointment_phone_check', methods: ['POST'])]
    public function checkPhone(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $payload = is_array($payload) ? $payload : $request->request->all();

        if (!$this->isCsrfTokenValid('create_appointment', (string) ($payload['_token'] ?? ''))) {
            return new JsonResponse([
                'available' => false,
                'message' => 'La session a expiré. Rechargez la page avant de continuer.',
            ], 403);
        }

        $currentUser = $this->getUser();
        $currentCustomer = $currentUser instanceof User ? $currentUser : null;
        $phoneCheck = $this->validatePhone((string) ($payload['phone'] ?? ''), $currentCustomer);

        if ($phoneCheck['message'] !== null) {
            return new JsonResponse([
                'available' => false,
                'requires_login' => $phoneCheck['requires_login'],
                'message' => $phoneCheck['message'],
                'login_url' => $this->generateUrl('app_login'),
            ]);
        }

        return new JsonResponse([
            'available' => true,
            'normalized_phone' => $phoneCheck['normalized_phone'],
        ]);
    }

    #[Route('/rendez-vous', name: 'app_public_appointment_create', methods: ['POST'])]
    public function create(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('create_appointment', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $data = [
            'email' => mb_strtolower(trim((string) $request->request->get('email'))),
            'phone' => trim((string) $request->request->get('phone')),
            'device' => trim((string) $request->request->get('device')),
            'problem' => trim((string) $request->request->get('problem')),
            'slot' => trim((string) $request->request->get('slot')),
            'customer_note' => trim((string) $request->request->get('customer_note')),
        ];

        $currentUser = $this->getUser();
        $currentCustomer = $currentUser instanceof User ? $currentUser : null;

        if ($currentCustomer instanceof User) {
            $data['email'] = $currentCustomer->getEmail();
            $data['phone'] = $currentCustomer->getPhone() ?? '';
        }

        $errors = $this->validate($data, $currentCustomer);
        $slotData = null;

        if ($errors === []) {
            $slotData = $this->appointmentScheduler->findBookableSlotData($data['slot']);

            if ($slotData === null) {
                $errors[] = 'Ce créneau n’est plus disponible. Choisissez un autre horaire.';
            }
        }

        if ($errors !== []) {
            $this->addFormFlash($request, $data, $errors);

            return $this->redirectAfterAppointmentError($request);
        }

        $account = $currentCustomer instanceof User
            ? ['user' => $currentCustomer, 'temporary_password' => null, 'created' => false]
            : $this->customerAccountFactory->findOrCreateCustomer($data['email'], $data['phone']);

        if (!$currentCustomer instanceof User && $account['temporary_password'] === null) {
            $this->addFormFlash($request, $data, [
                'Un compte existe déjà avec cette adresse email. Connectez-vous pour réserver avec ce compte.',
            ]);

            return $this->redirectAfterAppointmentError($request);
        }

        $normalizedPhone = User::normalizePhone($data['phone']);
        $account['user']->setPhone($normalizedPhone);

        $appointment = (new Appointment())
            ->setCustomer($account['user'])
            ->setEmail($account['user']->getEmail())
            ->setPhone($normalizedPhone)
            ->setDevice($this->appointmentScheduler->getDeviceLabel($data['device']))
            ->setProblem($this->appointmentScheduler->getProblemLabel($data['problem']))
            ->setScheduledAt($this->appointmentScheduler->parseSlotValue($slotData['value']))
            ->setDurationMinutes($slotData['duration_minutes'])
            ->setCustomerNote($data['customer_note']);

        $this->entityManager->persist($appointment);
        $this->loyaltyManager->createPendingAppointmentReward($appointment);

        if ($account['temporary_password'] !== null) {
            $request->getSession()->getFlashBag()->add('temporary_password', $account['temporary_password']);
            $this->security->login($account['user'], 'form_login', 'main', [(new RememberMeBadge())->enable()]);
        }

        $this->addFlash('success', 'Votre rendez-vous a bien été créé.');

        return $this->redirectToRoute('app_user_reservation_index');
    }

    /**
     * @param array{email: string, phone: string, device: string, problem: string, slot: string, customer_note: string} $data
     *
     * @return list<string>
     */
    private function validate(array $data, ?User $currentCustomer): array
    {
        $errors = [];

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Indiquez une adresse email valide.';
        }

        if (!$this->appointmentScheduler->isValidDevice($data['device'])) {
            $errors[] = 'Choisissez un appareil.';
        }

        if (!$this->appointmentScheduler->isValidProblem($data['problem'])) {
            $errors[] = 'Choisissez le problème rencontré.';
        }

        if ($data['phone'] === '') {
            $errors[] = $currentCustomer instanceof User
                ? 'Ajoutez un numéro de téléphone à votre profil avant de prendre rendez-vous.'
                : 'Indiquez un numéro de téléphone.';
        } else {
            $phoneCheck = $this->validatePhone($data['phone'], $currentCustomer);

            if ($phoneCheck['message'] !== null) {
                $errors[] = $phoneCheck['message'];
            }
        }

        if ($data['slot'] === '') {
            $errors[] = 'Choisissez un créneau.';
        }

        return $errors;
    }

    /**
     * @return array{message: string|null, normalized_phone: string, requires_login: bool}
     */
    private function validatePhone(string $phone, ?User $currentCustomer): array
    {
        $normalizedPhone = User::normalizePhone($phone);

        if ($normalizedPhone === '') {
            return [
                'message' => 'Indiquez un numéro de téléphone.',
                'normalized_phone' => '',
                'requires_login' => false,
            ];
        }

        if (preg_match('/^\+?\d{8,15}$/', $normalizedPhone) !== 1) {
            return [
                'message' => 'Indiquez un numéro de téléphone valide.',
                'normalized_phone' => $normalizedPhone,
                'requires_login' => false,
            ];
        }

        $phoneOwner = $this->userRepository->findOneByPhone($normalizedPhone);
        $phoneUsedByAnotherCustomer = $this->appointmentRepository->phoneExistsForAnotherCustomer($normalizedPhone, $currentCustomer);
        $phoneBelongsToAnotherCustomer = ($phoneOwner instanceof User && $phoneOwner->getId() !== $currentCustomer?->getId())
            || $phoneUsedByAnotherCustomer;

        if ($phoneBelongsToAnotherCustomer) {
            return [
                'message' => $currentCustomer instanceof User
                    ? 'Ce numéro de téléphone est déjà associé à un autre compte client.'
                    : 'Connectez-vous pour continuer avec ce numéro, ou vérifiez les informations saisies.',
                'normalized_phone' => $normalizedPhone,
                'requires_login' => !($currentCustomer instanceof User),
            ];
        }

        return [
            'message' => null,
            'normalized_phone' => $normalizedPhone,
            'requires_login' => false,
        ];
    }

    /**
     * @param array<string, string> $data
     * @param list<string>         $errors
     */
    private function addFormFlash(Request $request, array $data, array $errors): void
    {
        $flashBag = $request->getSession()->getFlashBag();
        $flashBag->add('appointment_data', $data);

        foreach ($errors as $error) {
            $flashBag->add('appointment_error', $error);
        }
    }

    private function redirectAfterAppointmentError(Request $request): RedirectResponse
    {
        $target = (string) $request->request->get('_redirect_to', 'home');

        if ($this->getUser() instanceof User) {
            if ($target === 'user_dashboard') {
                return $this->redirectToRoute('app_user_dashboard', ['modal' => 'appointment-modal']);
            }

            if ($target === 'user_reservation') {
                return $this->redirectToRoute('app_user_reservation_index', ['modal' => 'appointment-modal']);
            }
        }

        return $this->redirectToRoute('app_public_home');
    }
}
