<?php

declare(strict_types=1);

namespace App\Controller\Api\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\CustomerNotificationMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[IsGranted('ROLE_ADMIN')]
final class CustomerController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
        private readonly CustomerNotificationMailer $notificationMailer,
    ) {
    }

    #[Route('/api/admin/clients', name: 'app_api_admin_customer_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if ($csrfError = $this->csrfError('admin_customer_create', $request)) {
            return $csrfError;
        }

        $password = (string) $request->request->get('password');
        $customer = (new User())
            ->setEmail((string) $request->request->get('email'))
            ->setFirstName((string) $request->request->get('first_name'))
            ->setLastName((string) $request->request->get('last_name'))
            ->setPhone((string) $request->request->get('phone'))
            ->setRoles(['ROLE_USER'])
            ->setIsActive($request->request->getBoolean('is_active', true));

        $errors = $this->validateCustomer($customer, null);

        if (mb_strlen($password) < 8) {
            $errors[] = 'Le mot de passe doit contenir au moins 8 caractères.';
        }

        if ($errors !== []) {
            return $this->validationError($errors);
        }

        $customer
            ->setPassword($this->passwordHasher->hashPassword($customer, $password))
            ->requirePasswordChange();
        $this->entityManager->persist($customer);
        $this->entityManager->flush();
        $emailSent = $this->notificationMailer->sendAccountCreated($customer, $password, true);

        return $this->success(
            $emailSent
                ? 'Le client a été créé et son accès lui a été envoyé par e-mail.'
                : 'Le client a été créé, mais l’e-mail d’accès n’a pas pu être envoyé.',
            $request,
            $customer,
        );
    }

    #[Route('/api/admin/clients/{id}', name: 'app_api_admin_customer_update', methods: ['POST'])]
    public function update(User $customer, Request $request): JsonResponse
    {
        $this->denyUnlessCustomer($customer);

        if ($csrfError = $this->csrfError('admin_customer_edit_'.$customer->getId(), $request)) {
            return $csrfError;
        }

        $customer
            ->setEmail((string) $request->request->get('email'))
            ->setFirstName((string) $request->request->get('first_name'))
            ->setLastName((string) $request->request->get('last_name'))
            ->setPhone((string) $request->request->get('phone'))
            ->setIsActive($request->request->getBoolean('is_active'));

        $password = trim((string) $request->request->get('password'));
        $errors = $this->validateCustomer($customer, $customer);

        if ($password !== '' && mb_strlen($password) < 8) {
            $errors[] = 'Le nouveau mot de passe doit contenir au moins 8 caractères.';
        }

        if ($errors !== []) {
            return $this->validationError($errors);
        }

        if ($password !== '') {
            $customer->setPassword($this->passwordHasher->hashPassword($customer, $password));
        }

        $this->entityManager->flush();

        return $this->success('Le client a été mis à jour.', $request, $customer);
    }

    #[Route('/api/admin/clients/{id}/basculer', name: 'app_api_admin_customer_toggle', methods: ['POST'])]
    public function toggle(User $customer, Request $request): JsonResponse
    {
        $this->denyUnlessCustomer($customer);

        if ($csrfError = $this->csrfError('admin_customer_toggle_'.$customer->getId(), $request)) {
            return $csrfError;
        }

        $customer->setIsActive(!$customer->isActive());
        $this->entityManager->flush();

        return $this->success($customer->isActive() ? 'Le compte client est actif.' : 'Le compte client est désactivé.', $request, $customer);
    }

    #[Route('/api/admin/clients/{id}/supprimer', name: 'app_api_admin_customer_delete', methods: ['POST'])]
    public function delete(User $customer, Request $request): JsonResponse
    {
        $this->denyUnlessCustomer($customer);

        if ($csrfError = $this->csrfError('admin_customer_delete_'.$customer->getId(), $request)) {
            return $csrfError;
        }

        $customer->setIsActive(false);
        $this->entityManager->flush();

        return $this->success('Le client possède un historique : le compte a été désactivé au lieu d’être supprimé.', $request, $customer);
    }

    /**
     * @return list<string>
     */
    private function validateCustomer(User $customer, ?User $currentCustomer): array
    {
        $errors = [];

        foreach ($this->validator->validate($customer) as $violation) {
            $errors[] = $violation->getMessage();
        }

        $emailOwner = $this->userRepository->findOneByEmail($customer->getEmail());

        if ($emailOwner instanceof User && $emailOwner->getId() !== $currentCustomer?->getId()) {
            $errors[] = 'Cette adresse e-mail est déjà utilisée.';
        }

        $phone = $customer->getPhone();

        if ($phone === null) {
            $errors[] = 'Indiquez un numéro de téléphone.';
        } elseif (preg_match('/^\+?\d{8,15}$/', $phone) !== 1) {
            $errors[] = 'Indiquez un numéro de téléphone valide.';
        } else {
            $phoneOwner = $this->userRepository->findOneByPhone($phone);

            if ($phoneOwner instanceof User && $phoneOwner->getId() !== $currentCustomer?->getId()) {
                $errors[] = 'Ce numéro de téléphone est déjà utilisé.';
            }
        }

        return array_values(array_unique($errors));
    }

    private function denyUnlessCustomer(User $customer): void
    {
        if (in_array('ROLE_ADMIN', $customer->getRoles(), true)) {
            throw $this->createAccessDeniedException('Ce compte n’est pas géré depuis le module clients.');
        }
    }

    private function csrfError(string $id, Request $request): ?JsonResponse
    {
        if ($this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            return null;
        }

        return new JsonResponse([
            'success' => false,
            'message' => 'Jeton de sécurité invalide. Rechargez la page avant de réessayer.',
            'errors' => ['Jeton de sécurité invalide. Rechargez la page avant de réessayer.'],
        ], 403);
    }

    /**
     * @param list<string> $errors
     */
    private function validationError(array $errors): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => 'Le formulaire contient des erreurs.',
            'errors' => $errors,
        ], 422);
    }

    private function success(string $message, Request $request, ?User $focusCustomer = null): JsonResponse
    {
        $search = (string) $request->request->get('_list_search', '');
        $filter = (string) $request->request->get('_list_filter', '');
        $pagination = $this->userRepository->findCustomersForAdmin($search, $filter, 1, 0);
        $fragments = [
            [
                'selector' => '#customer-table-body',
                'html' => $this->renderView('admin/customer/partial/_table_body.html.twig', [
                    'pagination' => $pagination,
                    'search' => $search,
                    'filter' => $filter,
                ]),
            ],
            [
                'selector' => '#customer-modal-stack',
                'html' => $this->renderView('admin/customer/partial/_modal_stack.html.twig', [
                    'pagination' => $pagination,
                    'search' => $search,
                    'filter' => $filter,
                ]),
            ],
        ];

        if ($focusCustomer instanceof User) {
            $fragments[] = [
                'selector' => '#customer-detail-card',
                'html' => $this->renderView('admin/customer/partial/_detail_card.html.twig', [
                    'customer' => $focusCustomer,
                ]),
            ];
        }

        return new JsonResponse([
            'success' => true,
            'message' => $message,
            'fragments' => $fragments,
        ]);
    }
}
