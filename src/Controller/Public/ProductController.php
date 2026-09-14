<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\Product;
use App\Entity\User;
use App\Repository\ProductRepository;
use App\Service\GeneralSettingManager;
use App\Service\LoyaltyManager;
use App\Service\ProductReservationManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class ProductController extends AbstractController
{
    private const CART_KEY = 'promotion_cart_product_ids';

    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly ProductReservationManager $reservationManager,
        private readonly LoyaltyManager $loyaltyManager,
        private readonly GeneralSettingManager $generalSettingManager,
    ) {
    }

    #[Route('/promotions', name: 'app_public_product_index', methods: ['GET'])]
    public function index(Request $request, AuthenticationUtils $authenticationUtils): Response
    {
        $this->reservationManager->expireOverdueReservations();
        $search = (string) $request->query->get('q', '');
        $user = $this->getUser();
        $cartProductIds = $user instanceof User ? $this->getCartProductIds($request) : [];
        $cartProducts = $this->reservationManager->getCartProducts($cartProductIds);
        $cartProductIds = array_map(static fn (Product $product): int => (int) $product->getId(), $cartProducts);

        if ($user instanceof User) {
            $request->getSession()->set(self::CART_KEY, $cartProductIds);
        } else {
            $request->getSession()->remove(self::CART_KEY);
        }

        return $this->render('public/product/index.html.twig', [
            // The complete catalogue stays in the DOM so the instant client-side search
            // can restore every product without another request.
            'products' => $this->productRepository->findForStore(),
            'reserved_product_ids' => $this->productRepository->findBlockedProductIds(),
            'cart_products' => $cartProducts,
            'cart_product_ids' => $cartProductIds,
            'cart_total_cents' => $this->cartTotal($cartProducts),
            'search' => $search,
            'loyalty' => $user instanceof User ? $this->loyaltyManager->buildAccountView($user, 4) : null,
            'setting' => $this->generalSettingManager->getSetting(),
            'last_username' => $authenticationUtils->getLastUsername(),
            'login_error' => $authenticationUtils->getLastAuthenticationError(),
            'open_modal' => $request->query->get('modal'),
            'auth_panel' => $request->query->get('auth') === 'register' ? 'register' : 'login',
        ]);
    }

    #[Route('/promotions/{id}', name: 'app_public_product_show', methods: ['GET'])]
    public function show(Product $product, Request $request, AuthenticationUtils $authenticationUtils): Response
    {
        $this->reservationManager->expireOverdueReservations();
        $user = $this->getUser();
        $cartProductIds = $user instanceof User ? $this->getCartProductIds($request) : [];
        $cartProducts = $this->reservationManager->getCartProducts($cartProductIds);

        return $this->render('public/product/show.html.twig', [
            'product' => $product,
            'is_reserved' => $this->productRepository->isBlocked($product),
            'cart_products' => $cartProducts,
            'cart_product_ids' => $cartProductIds,
            'cart_total_cents' => $this->cartTotal($cartProducts),
            'loyalty' => $user instanceof User ? $this->loyaltyManager->buildAccountView($user, 4) : null,
            'setting' => $this->generalSettingManager->getSetting(),
            'last_username' => $authenticationUtils->getLastUsername(),
            'login_error' => $authenticationUtils->getLastAuthenticationError(),
            'open_modal' => $request->query->get('modal'),
            'auth_panel' => $request->query->get('auth') === 'register' ? 'register' : 'login',
        ]);
    }

    #[Route('/promotions/panier/{id}/ajouter', name: 'app_public_product_cart_add', methods: ['POST'])]
    public function addToCart(Product $product, Request $request): RedirectResponse
    {
        if (!$this->getUser() instanceof User) {
            $this->addFlash('error', 'Connectez-vous ou créez un compte pour réserver cet article.');

            return $this->redirectToRoute('app_public_product_index', ['modal' => 'login-modal']);
        }

        $this->denyUnlessValidCsrf('product_cart_add_'.$product->getId(), $request);
        $this->reservationManager->expireOverdueReservations();

        if (!$product->isActive()) {
            $this->addFlash('error', 'Cet article n’est plus disponible.');

            return $this->redirectToRoute('app_public_product_index');
        }

        if ($this->productRepository->isBlocked($product)) {
            $this->addFlash('error', 'Cet article est déjà réservé.');

            return $this->redirectToRoute('app_public_product_index');
        }

        $cartProductIds = $this->getCartProductIds($request);
        $productId = (int) $product->getId();

        if (!in_array($productId, $cartProductIds, true)) {
            $cartProductIds[] = $productId;
        }

        $request->getSession()->set(self::CART_KEY, $cartProductIds);
        $this->addFlash('success', 'L’article a été ajouté au panier.');

        return $this->redirectToRoute('app_public_product_index', ['modal' => 'cart-modal']);
    }

    #[Route('/promotions/panier/{id}/retirer', name: 'app_public_product_cart_remove', methods: ['POST'])]
    public function removeFromCart(Product $product, Request $request): RedirectResponse
    {
        $this->denyUnlessValidCsrf('product_cart_remove_'.$product->getId(), $request);
        $productId = (int) $product->getId();
        $cartProductIds = array_values(array_filter(
            $this->getCartProductIds($request),
            static fn (int $id): bool => $id !== $productId,
        ));

        $request->getSession()->set(self::CART_KEY, $cartProductIds);
        $this->addFlash('success', 'L’article a été retiré du panier.');

        return $this->redirectToRoute('app_public_product_index', ['modal' => 'cart-modal']);
    }

    #[Route('/promotions/reserver', name: 'app_public_product_reserve', methods: ['POST'])]
    public function reserve(Request $request): RedirectResponse
    {
        $this->denyUnlessValidCsrf('product_cart_reserve', $request);

        $user = $this->getUser();

        if (!$user instanceof User) {
            $this->addFlash('error', 'Connectez-vous ou créez un compte pour réserver vos articles.');

            return $this->redirectToRoute('app_public_product_index', ['modal' => 'login-modal']);
        }

        try {
            $reservation = $this->reservationManager->createReservation(
                $user,
                $this->getCartProductIds($request),
                $request->request->getBoolean('use_loyalty'),
            );
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_public_product_index', ['modal' => 'cart-modal']);
        }

        $request->getSession()->remove(self::CART_KEY);
        $this->addFlash('success', sprintf(
            'Votre réservation boutique #%d est confirmée. Les articles sont gardés en magasin.',
            $reservation->getId(),
        ));

        return $this->redirectToRoute('app_user_product_reservation_index');
    }

    /** @return list<int> */
    private function getCartProductIds(Request $request): array
    {
        $cart = $request->getSession()->get(self::CART_KEY, []);

        if (!is_array($cart)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $cart),
            static fn (int $id): bool => $id > 0,
        )));
    }

    /** @param list<Product> $products */
    private function cartTotal(array $products): int
    {
        return array_sum(array_map(static fn (Product $product): int => $product->getPromotionalPriceCents(), $products));
    }

    private function denyUnlessValidCsrf(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }
}
