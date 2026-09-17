<?php

declare(strict_types=1);

namespace App\Controller\User;

use App\Entity\User;
use App\Service\StoreCheckInManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class StoreCheckInController extends AbstractController
{
    public function __construct(private readonly StoreCheckInManager $checkInManager)
    {
    }

    #[Route('/espace-client/presence-magasin/{token}', name: 'app_user_store_check_in', requirements: ['token' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function show(string $token): Response
    {
        $customer = $this->customer();
        $this->denyUnlessValidToken($token);

        if (in_array('ROLE_ADMIN', $customer->getRoles(), true)) {
            throw $this->createAccessDeniedException('Cette action est réservée aux clients.');
        }

        return $this->render('user/check_in/show.html.twig', [
            'customer' => $customer,
            'token' => $token,
            'check_in' => $this->checkInManager->getUnresolvedForCustomer($customer),
            'setting' => $this->checkInManager->getSetting(),
        ]);
    }

    #[Route('/espace-client/presence-magasin/{token}', name: 'app_user_store_check_in_confirm', requirements: ['token' => '[a-f0-9]{64}'], methods: ['POST'])]
    public function confirm(string $token, Request $request): RedirectResponse
    {
        $customer = $this->customer();
        $this->denyUnlessValidToken($token);

        if (!$this->isCsrfTokenValid('store_check_in', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        try {
            $result = $this->checkInManager->checkIn($customer);
            $message = $result['throttled']
                ? 'Votre présence est déjà signalée. L’équipe a conservé votre demande.'
                : 'Votre présence a bien été signalée à l’équipe. Vous pouvez rester sur cette page.';
            $this->addFlash('success', $message);
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_user_store_check_in', ['token' => $token]);
    }

    private function denyUnlessValidToken(string $token): void
    {
        if (!$this->checkInManager->isValidToken($token)) {
            throw $this->createNotFoundException('Ce QR code magasin est invalide ou désactivé.');
        }
    }

    private function customer(): User
    {
        $customer = $this->getUser();

        if (!$customer instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $customer;
    }
}
