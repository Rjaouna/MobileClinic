<?php

declare(strict_types=1);

namespace App\Controller\Api\Admin;

use App\Repository\ProductReservationRepository;
use App\Service\GeneralSettingManager;
use App\Service\ProductReservationManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class ProductController extends AbstractController
{
    public function __construct(
        private readonly ProductReservationManager $reservationManager,
        private readonly ProductReservationRepository $reservationRepository,
        private readonly GeneralSettingManager $generalSettingManager,
    ) {
    }

    #[Route('/api/admin/promotions/reservations/actualisation', name: 'app_api_admin_product_reservation_refresh', methods: ['GET'])]
    public function refresh(): JsonResponse
    {
        $result = $this->reservationManager->expireOverdueReservations();
        $fragments = [];

        if ($result['expired'] > 0) {
            $fragments[] = [
                'selector' => '#admin-product-reservation-table',
                'html' => $this->renderView('admin/product/partial/_reservation_table.html.twig', [
                    'reservations' => $this->reservationRepository->findForAdmin(),
                    'setting' => $this->generalSettingManager->getSetting(),
                ]),
            ];
        }

        return $this->json([
            'success' => true,
            'expired' => $result['expired'],
            'fragments' => $fragments,
        ]);
    }
}
