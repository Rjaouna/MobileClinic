<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\LoyaltyManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class LoyaltyController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly LoyaltyManager $loyaltyManager,
    ) {
    }

    #[Route('/admin/fidelite', name: 'app_admin_loyalty_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search = (string) $request->query->get('q', '');
        $filter = (string) $request->query->get('filter', '');

        return $this->render('admin/loyalty/index.html.twig', [
            'pagination' => $this->userRepository->findCustomersForAdmin($search, $filter, 1, 0),
            'search' => $search,
            'filter' => $filter,
            'setting' => $this->loyaltyManager->getSetting(),
        ]);
    }

    #[Route('/admin/fidelite/{id}', name: 'app_admin_loyalty_show', methods: ['GET'])]
    public function show(User $customer): Response
    {
        if (in_array('ROLE_ADMIN', $customer->getRoles(), true)) {
            throw $this->createAccessDeniedException('Ce compte n’est pas une carte fidélité client.');
        }

        return $this->render('admin/loyalty/show.html.twig', [
            'customer' => $customer,
            'loyalty' => $this->loyaltyManager->buildAccountView($customer, 20),
            'setting' => $this->loyaltyManager->getSetting(),
        ]);
    }
}
