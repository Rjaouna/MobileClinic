<?php

declare(strict_types=1);

namespace App\Controller\User;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ProfileController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('/espace-client/profil', name: 'app_user_profile_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('user/profile/index.html.twig', [
            'customer' => $this->getCustomer(),
        ]);
    }

    #[Route('/espace-client/profil/mot-de-passe', name: 'app_user_profile_password_update', methods: ['POST'])]
    public function updatePassword(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('user_profile_password', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $customer = $this->getCustomer();
        $password = (string) $request->request->get('password');
        $passwordConfirmation = (string) $request->request->get('password_confirmation');

        if (mb_strlen($password) < 8) {
            $this->addFlash('error', 'Le nouveau mot de passe doit contenir au moins 8 caractères.');

            return $this->redirectToRoute('app_user_profile_index');
        }

        if ($password !== $passwordConfirmation) {
            $this->addFlash('error', 'La confirmation du mot de passe ne correspond pas.');

            return $this->redirectToRoute('app_user_profile_index');
        }

        $customer
            ->setPassword($this->passwordHasher->hashPassword($customer, $password))
            ->markPasswordChanged();

        $this->entityManager->flush();
        $this->addFlash('success', 'Votre mot de passe a été mis à jour.');

        return $this->redirectToRoute('app_user_profile_index');
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
