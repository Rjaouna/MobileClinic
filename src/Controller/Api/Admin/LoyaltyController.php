<?php

declare(strict_types=1);

namespace App\Controller\Api\Admin;

use App\Entity\LoyaltyTransaction;
use App\Entity\User;
use App\Service\LoyaltyManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class LoyaltyController extends AbstractController
{
    public function __construct(private readonly LoyaltyManager $loyaltyManager)
    {
    }

    #[Route('/api/admin/fidelite/clients/{id}/credit', name: 'app_api_admin_loyalty_credit', methods: ['POST'])]
    public function credit(User $customer, Request $request): JsonResponse
    {
        $this->denyUnlessCustomer($customer);

        if ($csrfError = $this->csrfError('admin_loyalty_credit_'.$customer->getId(), $request)) {
            return $csrfError;
        }

        try {
            $this->loyaltyManager->manualCredit(
                $customer,
                $this->parseMoneyToCents((string) $request->request->get('amount')),
                (string) $request->request->get('reason'),
                $this->getAdmin(),
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->validationError($exception->getMessage());
        }

        return $this->success($customer, 'Le crédit fidélité a été ajouté.');
    }

    #[Route('/api/admin/fidelite/clients/{id}/utiliser', name: 'app_api_admin_loyalty_redeem', methods: ['POST'])]
    public function redeem(User $customer, Request $request): JsonResponse
    {
        $this->denyUnlessCustomer($customer);

        if ($csrfError = $this->csrfError('admin_loyalty_redeem_'.$customer->getId(), $request)) {
            return $csrfError;
        }

        try {
            $reasonType = trim((string) $request->request->get('reason_type'));
            $reason = trim((string) $request->request->get('reason'));
            $this->loyaltyManager->redeem(
                $customer,
                $this->parseMoneyToCents((string) $request->request->get('amount')),
                trim($reasonType.' '.$reason),
                $this->getAdmin(),
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->validationError($exception->getMessage());
        }

        return $this->success($customer, 'La cagnotte utilisée a été débitée.');
    }

    #[Route('/api/admin/fidelite/clients/{id}/corriger', name: 'app_api_admin_loyalty_adjust', methods: ['POST'])]
    public function adjust(User $customer, Request $request): JsonResponse
    {
        $this->denyUnlessCustomer($customer);

        if ($csrfError = $this->csrfError('admin_loyalty_adjust_'.$customer->getId(), $request)) {
            return $csrfError;
        }

        try {
            $this->loyaltyManager->manualAdjustment(
                $customer,
                $this->parseMoneyToCents((string) $request->request->get('amount'), true),
                (string) $request->request->get('reason'),
                $this->getAdmin(),
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->validationError($exception->getMessage());
        }

        return $this->success($customer, 'La correction a été enregistrée.');
    }

    #[Route('/api/admin/fidelite/mouvements/{id}/annuler', name: 'app_api_admin_loyalty_cancel', methods: ['POST'])]
    public function cancel(LoyaltyTransaction $transaction, Request $request): JsonResponse
    {
        if ($csrfError = $this->csrfError('admin_loyalty_cancel_'.$transaction->getId(), $request)) {
            return $csrfError;
        }

        try {
            $this->loyaltyManager->cancelPendingTransaction(
                $transaction,
                (string) $request->request->get('reason'),
                $this->getAdmin(),
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->validationError($exception->getMessage());
        }

        $customer = $transaction->getAccount()?->getCustomer();

        if (!$customer instanceof User) {
            return $this->validationError('Le client lié au mouvement est introuvable.');
        }

        return $this->success($customer, 'Le mouvement en attente a été annulé.');
    }

    #[Route('/api/admin/fidelite/reglages', name: 'app_api_admin_loyalty_settings', methods: ['POST'])]
    public function settings(Request $request): JsonResponse
    {
        if ($csrfError = $this->csrfError('admin_loyalty_settings', $request)) {
            return $csrfError;
        }

        try {
            $setting = $this->loyaltyManager->updateSetting(
                $this->parseMoneyToCents((string) $request->request->get('appointment_reward')),
                $request->request->getBoolean('is_enabled'),
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->validationError($exception->getMessage());
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'Les réglages fidélité ont été mis à jour.',
            'fragments' => [
                [
                    'selector' => '#loyalty-settings-state',
                    'html' => $this->renderView('admin/loyalty/partial/_settings_state.html.twig', [
                        'setting' => $setting,
                    ]),
                ],
            ],
        ]);
    }

    private function denyUnlessCustomer(User $customer): void
    {
        if (in_array('ROLE_ADMIN', $customer->getRoles(), true)) {
            throw $this->createAccessDeniedException('Ce compte n’est pas une carte fidélité client.');
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

    private function getAdmin(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function parseMoneyToCents(string $value, bool $allowNegative = false): int
    {
        $value = trim(str_replace(["\xc2\xa0", ' ', '€'], '', $value));
        $pattern = $allowNegative ? '/^([+-]?)(\d+)(?:[,.](\d{1,2}))?$/' : '/^(\+?)(\d+)(?:[,.](\d{1,2}))?$/';

        if (preg_match($pattern, $value, $matches) !== 1) {
            throw new \InvalidArgumentException('Indiquez un montant valide.');
        }

        $sign = $matches[1] === '-' ? -1 : 1;
        $euros = (int) $matches[2];
        $cents = (int) str_pad($matches[3] ?? '0', 2, '0');
        $amountCents = $sign * (($euros * 100) + $cents);

        if (!$allowNegative && $amountCents <= 0) {
            throw new \InvalidArgumentException('Le montant doit être positif.');
        }

        return $amountCents;
    }

    private function validationError(string $message): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $message,
            'errors' => [$message],
        ], 422);
    }

    private function success(User $customer, string $message): JsonResponse
    {
        $loyalty = $this->loyaltyManager->buildAccountView($customer, 20);

        return new JsonResponse([
            'success' => true,
            'message' => $message,
            'loyalty_balance' => [
                'customer_id' => $customer->getId(),
                'available_balance_cents' => $loyalty['available_balance_cents'],
                'available_balance_label' => $this->formatMoney($loyalty['available_balance_cents']),
            ],
            'fragments' => [
                [
                    'selector' => '#loyalty-detail-card',
                    'html' => $this->renderView('admin/loyalty/partial/_detail_card.html.twig', [
                        'customer' => $customer,
                        'loyalty' => $loyalty,
                    ]),
                ],
                [
                    'selector' => '#loyalty-transaction-list',
                    'html' => $this->renderView('admin/loyalty/partial/_transactions.html.twig', [
                        'customer' => $customer,
                        'loyalty' => $loyalty,
                    ]),
                ],
                [
                    'selector' => '#loyalty-modal-stack',
                    'html' => $this->renderView('admin/loyalty/partial/_modal_stack.html.twig', [
                        'customer' => $customer,
                        'loyalty' => $loyalty,
                    ]),
                ],
            ],
        ]);
    }

    private function formatMoney(int $amountCents): string
    {
        $euros = intdiv(abs($amountCents), 100);
        $cents = abs($amountCents) % 100;

        return sprintf(
            '%s%d%s €',
            $amountCents < 0 ? '-' : '',
            $euros,
            $cents > 0 ? sprintf(',%02d', $cents) : '',
        );
    }
}
