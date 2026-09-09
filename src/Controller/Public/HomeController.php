<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Service\HomePageDataProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class HomeController extends AbstractController
{
    public function __construct(private readonly HomePageDataProvider $homePageDataProvider)
    {
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
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectAfterLogin();
        }

        return $this->render('public/home/index.html.twig', $this->homePageDataProvider->getData([
            'last_username' => $authenticationUtils->getLastUsername(),
            'login_error' => $authenticationUtils->getLastAuthenticationError(),
            'open_modal' => 'login-modal',
        ]));
    }

    #[Route('/apres-connexion', name: 'app_after_login', methods: ['GET'])]
    public function afterLogin(): RedirectResponse
    {
        return $this->redirectAfterLogin();
    }

    #[Route('/deconnexion', name: 'app_logout', methods: ['GET'])]
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
}
