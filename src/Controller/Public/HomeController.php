<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\HomePageDataProvider;
use App\Service\GeneralSettingManager;
use App\Service\CustomerNotificationMailer;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class HomeController extends AbstractController
{
    public function __construct(
        private readonly HomePageDataProvider $homePageDataProvider,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
        private readonly Security $security,
        private readonly GeneralSettingManager $generalSettingManager,
        private readonly CustomerNotificationMailer $notificationMailer,
    ) {
    }

    #[Route('/', name: 'app_public_home', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $appointmentData = $request->getSession()->getFlashBag()->get('appointment_data');
        $appointmentErrors = $request->getSession()->getFlashBag()->get('appointment_error');

        return $this->render('public/home/index.html.twig', $this->homePageDataProvider->getData([
            'appointment_data' => $appointmentData[0] ?? [],
            'appointment_errors' => $appointmentErrors,
            'open_modal' => $appointmentErrors !== [] ? 'appointment-modal' : $request->query->get('modal'),
        ]));
    }

    #[Route('/connexion', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(Request $request, AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectAfterLogin();
        }

        return $this->render('public/home/index.html.twig', $this->homePageDataProvider->getData([
            'last_username' => $authenticationUtils->getLastUsername(),
            'login_error' => $authenticationUtils->getLastAuthenticationError(),
            'registration_data' => $this->getRegistrationFlash($request, 'registration_data', []),
            'registration_errors' => $this->getRegistrationFlash($request, 'registration_error', []),
            'auth_panel' => $request->query->get('auth') === 'register' ? 'register' : 'login',
            'open_modal' => 'login-modal',
        ]));
    }

    #[Route('/inscription', name: 'app_register', methods: ['POST'])]
    public function register(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectAfterLogin();
        }

        if (!$this->isCsrfTokenValid('register_customer', (string) $request->request->get('_token'))) {
            return new Response('Jeton de sécurité invalide.', Response::HTTP_FORBIDDEN);
        }

        $data = [
            'first_name' => trim((string) $request->request->get('first_name')),
            'last_name' => trim((string) $request->request->get('last_name')),
            'email' => mb_strtolower(trim((string) $request->request->get('email'))),
            'phone' => trim((string) $request->request->get('phone')),
        ];
        $password = (string) $request->request->get('password');
        $passwordConfirmation = (string) $request->request->get('password_confirmation');

        $customer = (new User())
            ->setEmail($data['email'])
            ->setFirstName($data['first_name'])
            ->setLastName($data['last_name'])
            ->setPhone($data['phone'])
            ->setRoles(['ROLE_USER'])
            ->setIsActive(true);

        $errors = $this->validateRegistration($customer, $password, $passwordConfirmation);

        if ($errors !== []) {
            $this->addRegistrationFlash($request, $data, $errors);

            return $this->redirectToRoute('app_login', ['auth' => 'register']);
        }

        $customer->setPassword($this->passwordHasher->hashPassword($customer, $password));
        $this->entityManager->persist($customer);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            $this->addRegistrationFlash($request, $data, [
                'Cette adresse e-mail ou ce numéro de téléphone est déjà utilisé.',
            ]);

            return $this->redirectToRoute('app_login', ['auth' => 'register']);
        }

        $emailSent = $this->notificationMailer->sendAccountCreated($customer);
        $this->addFlash(
            $emailSent ? 'success' : 'warning',
            $emailSent
                ? 'Votre compte client a bien été créé. Un e-mail de confirmation vient de vous être envoyé.'
                : 'Votre compte est créé, mais l’e-mail de confirmation n’a pas pu être envoyé. Vous pouvez tout de même vous connecter.',
        );
        $this->security->login($customer, 'form_login', 'main', [(new RememberMeBadge())->enable()]);

        $targetPath = $this->sanitizeTargetPath((string) $request->request->get('_target_path'));

        if ($targetPath !== null) {
            return $this->redirect($targetPath);
        }

        return $this->redirectToRoute('app_user_dashboard');
    }

    #[Route('/apres-connexion', name: 'app_after_login', methods: ['GET'])]
    public function afterLogin(): RedirectResponse
    {
        return $this->redirectAfterLogin();
    }

    #[Route('/mentions-legales', name: 'app_public_legal_notice', methods: ['GET'])]
    public function legalNotice(): Response
    {
        $setting = $this->generalSettingManager->getSetting();

        return $this->render('public/legal/legal_notice.html.twig', [
            'setting' => $setting,
            'legal' => $setting->getLegalProfile(),
        ]);
    }

    #[Route('/confidentialite', name: 'app_public_privacy', methods: ['GET'])]
    public function privacy(): Response
    {
        $setting = $this->generalSettingManager->getSetting();

        return $this->render('public/legal/privacy.html.twig', [
            'setting' => $setting,
            'legal' => $setting->getLegalProfile(),
        ]);
    }

    #[Route('/deconnexion', name: 'app_logout', methods: ['POST'])]
    public function logout(): void
    {
        throw new \LogicException('La déconnexion est gérée par le firewall Symfony.');
    }

    private function redirectAfterLogin(): RedirectResponse
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('app_admin_dashboard');
        }

        return $this->redirectToRoute('app_user_dashboard');
    }

    private function sanitizeTargetPath(string $targetPath): ?string
    {
        $targetPath = trim($targetPath);

        if ($targetPath === '' || str_starts_with($targetPath, '//') || !str_starts_with($targetPath, '/')) {
            return null;
        }

        return $targetPath;
    }

    /**
     * @return list<string>
     */
    private function validateRegistration(User $customer, string $password, string $passwordConfirmation): array
    {
        $errors = [];

        foreach ($this->validator->validate($customer) as $violation) {
            $errors[] = $violation->getMessage();
        }

        if ($customer->getFirstName() === null) {
            $errors[] = 'Indiquez votre prénom.';
        }

        if ($customer->getLastName() === null) {
            $errors[] = 'Indiquez votre nom.';
        }

        if ($customer->getPhone() === null || preg_match('/^\+?\d{8,15}$/', $customer->getPhone()) !== 1) {
            $errors[] = 'Indiquez un numéro de téléphone valide.';
        }

        if ($this->userRepository->findOneByEmail($customer->getEmail()) instanceof User) {
            $errors[] = 'Cette adresse e-mail est déjà utilisée.';
        }

        if ($customer->getPhone() !== null && $this->userRepository->findOneByPhone($customer->getPhone()) instanceof User) {
            $errors[] = 'Ce numéro de téléphone est déjà utilisé.';
        }

        if (mb_strlen($password) < 8) {
            $errors[] = 'Le mot de passe doit contenir au moins 8 caractères.';
        }

        if ($password !== $passwordConfirmation) {
            $errors[] = 'La confirmation du mot de passe ne correspond pas.';
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param array<string, string> $data
     * @param list<string>         $errors
     */
    private function addRegistrationFlash(Request $request, array $data, array $errors): void
    {
        $flashBag = $request->getSession()->getFlashBag();
        $flashBag->add('registration_data', $data);

        foreach ($errors as $error) {
            $flashBag->add('registration_error', $error);
        }
    }

    /**
     * @template T
     *
     * @param T $default
     *
     * @return T|array
     */
    private function getRegistrationFlash(Request $request, string $type, mixed $default): mixed
    {
        $items = $request->getSession()->getFlashBag()->get($type);

        if ($items === []) {
            return $default;
        }

        return $type === 'registration_data' ? ($items[0] ?? $default) : $items;
    }
}
