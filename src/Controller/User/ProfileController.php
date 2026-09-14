<?php

declare(strict_types=1);

namespace App\Controller\User;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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

    #[Route('/espace-client/profil/informations', name: 'app_user_profile_information_update', methods: ['POST'])]
    public function updateInformation(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('user_profile_information', (string) $request->request->get('_token'))) {
            if ($request->isXmlHttpRequest()) {
                return $this->jsonError(['Jeton de sécurité invalide. Rechargez la page avant de réessayer.'], 403);
            }

            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $customer = $this->getCustomer();
        $firstName = trim((string) $request->request->get('first_name'));
        $lastName = trim((string) $request->request->get('last_name'));
        $errors = [];

        if (mb_strlen($firstName) > 100) {
            $errors[] = 'Le prénom ne peut pas dépasser 100 caractères.';
        }

        if (mb_strlen($lastName) > 100) {
            $errors[] = 'Le nom ne peut pas dépasser 100 caractères.';
        }

        if ($errors !== []) {
            if ($request->isXmlHttpRequest()) {
                return $this->jsonError($errors);
            }

            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirectToRoute('app_user_profile_index');
        }

        $customer
            ->setFirstName($firstName)
            ->setLastName($lastName);

        $this->entityManager->flush();

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'success' => true,
                'message' => 'Votre profil a été mis à jour.',
                'fragments' => $this->profileFragments($customer),
            ]);
        }

        $this->addFlash('success', 'Votre profil a été mis à jour.');

        return $this->redirectToRoute('app_user_profile_index');
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

    /**
     * @return list<array{selector: string, html: string}>
     */
    private function profileFragments(User $customer): array
    {
        return [
            [
                'selector' => '#user-profile-account-line',
                'html' => $this->renderView('user/partial/_account_line.html.twig', [
                    'id' => 'user-profile-account-line',
                    'customer' => $customer,
                ]),
            ],
            [
                'selector' => '#user-profile-summary',
                'html' => $this->renderView('user/profile/partial/_summary.html.twig', [
                    'customer' => $customer,
                ]),
            ],
        ];
    }

    /**
     * @param list<string> $errors
     */
    private function jsonError(array $errors, int $statusCode = 422): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $errors[0] ?? 'Le formulaire contient des erreurs.',
            'errors' => $errors,
        ], $statusCode);
    }
}
