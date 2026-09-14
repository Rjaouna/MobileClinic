<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\AppointmentRepository;
use App\Repository\UserRepository;
use App\Service\LoyaltyManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class CustomerController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly AppointmentRepository $appointmentRepository,
        private readonly LoyaltyManager $loyaltyManager,
    ) {
    }

    #[Route('/admin/clients', name: 'app_admin_customer_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search = (string) $request->query->get('q', '');
        $filter = (string) $request->query->get('filter', '');

        return $this->render('admin/customer/index.html.twig', [
            'pagination' => $this->userRepository->findCustomersForAdmin($search, $filter, 1, 0),
            'search' => $search,
            'filter' => $filter,
        ]);
    }

    #[Route('/admin/clients/{id}', name: 'app_admin_customer_show', methods: ['GET'])]
    public function show(User $customer): Response
    {
        $this->denyIfAdminAccount($customer);

        return $this->render('admin/customer/show.html.twig', [
            'customer' => $customer,
            'appointments' => $this->appointmentRepository->findForUser($customer),
            'loyalty' => $this->loyaltyManager->buildAccountView($customer, 12),
        ]);
    }

    private function denyIfAdminAccount(User $customer): void
    {
        if (in_array('ROLE_ADMIN', $customer->getRoles(), true)) {
            throw $this->createAccessDeniedException('Ce compte n’est pas géré depuis le module clients.');
        }
    }
}
