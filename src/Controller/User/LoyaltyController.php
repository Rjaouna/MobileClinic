<?php

declare(strict_types=1);

namespace App\Controller\User;

use App\Entity\User;
use App\Service\LoyaltyManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class LoyaltyController extends AbstractController
{
    public function __construct(private readonly LoyaltyManager $loyaltyManager)
    {
    }

    #[Route('/espace-client/fidelite', name: 'app_user_loyalty_index', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('user/loyalty/index.html.twig', [
            'customer' => $user,
            'loyalty' => $this->loyaltyManager->buildAccountView($user, 8),
        ]);
    }
}
