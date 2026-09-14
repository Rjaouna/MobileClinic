<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Product;
use App\Entity\ProductReservation;
use App\Entity\User;
use App\Repository\ProductRepository;
use App\Repository\ProductReservationRepository;
use App\Service\ProductReservationManager;
use App\Service\GeneralSettingManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class ProductController extends AbstractController
{
    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly ProductReservationRepository $reservationRepository,
        private readonly ProductReservationManager $reservationManager,
        private readonly GeneralSettingManager $generalSettingManager,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/admin/promotions', name: 'app_admin_product_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->reservationManager->expireOverdueReservations();
        $search = (string) $request->query->get('q', '');
        $filter = (string) $request->query->get('filter', '');
        $reservationStatus = (string) $request->query->get('reservation_status', '');

        return $this->render('admin/product/index.html.twig', [
            'products' => $this->productRepository->findForAdmin($search, $filter),
            'reserved_product_ids' => $this->productRepository->findBlockedProductIds(),
            'reservations' => $this->reservationRepository->findForAdmin(),
            'reservation_status_labels' => ProductReservation::STATUS_LABELS,
            'search' => $search,
            'filter' => $filter,
            'reservation_status' => $reservationStatus,
            'setting' => $this->generalSettingManager->getSetting(),
        ]);
    }

    #[Route('/admin/promotions/articles/{id}', name: 'app_admin_product_show', methods: ['GET'])]
    public function show(Product $product): Response
    {
        return $this->render('admin/product/show.html.twig', [
            'product' => $product,
            'is_reserved' => $this->productRepository->isBlocked($product),
        ]);
    }

    #[Route('/admin/promotions/articles', name: 'app_admin_product_create', methods: ['POST'])]
    public function create(Request $request): RedirectResponse
    {
        $this->denyUnlessValidCsrf('admin_product_create', $request);
        $product = new Product();

        try {
            $this->applyProductData($product, $request);
            $this->entityManager->persist($product);
            $this->entityManager->flush();
            $this->addFlash('success', 'L’article promotionnel a été ajouté.');
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_admin_product_index');
    }

    #[Route('/admin/promotions/articles/{id}/modifier', name: 'app_admin_product_update', methods: ['POST'])]
    public function update(Product $product, Request $request): RedirectResponse
    {
        $this->denyUnlessValidCsrf('admin_product_'.$product->getId(), $request);

        try {
            $this->applyProductData($product, $request);
            $this->entityManager->flush();
            $this->addFlash('success', 'L’article promotionnel a été mis à jour.');
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_admin_product_index');
    }

    #[Route('/admin/promotions/articles/{id}/basculer', name: 'app_admin_product_toggle', methods: ['POST'])]
    public function toggle(Product $product, Request $request): RedirectResponse
    {
        $this->denyUnlessValidCsrf('admin_product_'.$product->getId(), $request);

        if ($product->isSold()) {
            $this->addFlash('error', 'Un article vendu ne peut plus être remis en boutique.');

            return $this->redirectToRoute('app_admin_product_index');
        }

        $product->setIsActive(!$product->isActive());
        $this->entityManager->flush();
        $this->addFlash('success', $product->isActive() ? 'L’article est de nouveau visible.' : 'L’article est masqué de la boutique.');

        return $this->redirectToRoute('app_admin_product_index');
    }

    #[Route('/admin/promotions/articles/{id}/supprimer', name: 'app_admin_product_delete', methods: ['POST'])]
    public function delete(Product $product, Request $request): RedirectResponse
    {
        $this->denyUnlessValidCsrf('admin_product_delete_'.$product->getId(), $request);

        if ($this->productRepository->isBlocked($product)) {
            $this->addFlash('error', 'Impossible de supprimer cet article tant qu’une réservation active le bloque.');

            return $this->redirectToRoute('app_admin_product_index');
        }

        $this->entityManager->remove($product);
        $this->entityManager->flush();
        $this->addFlash('success', 'L’article a été supprimé du catalogue.');

        return $this->redirectToRoute('app_admin_product_index');
    }

    #[Route('/admin/promotions/reservations/{id}/valider', name: 'app_admin_product_reservation_confirm', methods: ['POST'])]
    public function confirmReservation(ProductReservation $reservation, Request $request): RedirectResponse
    {
        $this->denyUnlessValidCsrf('admin_product_reservation_'.$reservation->getId(), $request);

        try {
            $this->reservationManager->confirm($reservation, $this->getAdmin(), (string) $request->request->get('admin_note'));
            $this->addFlash('success', 'La réservation boutique a été validée définitivement.');
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_admin_product_index', ['reservation_status' => ProductReservation::STATUS_RESERVED]);
    }

    #[Route('/admin/promotions/reservations/{id}/retirer', name: 'app_admin_product_reservation_withdraw', methods: ['POST'])]
    public function withdrawReservation(ProductReservation $reservation, Request $request): RedirectResponse
    {
        $this->denyUnlessValidCsrf('admin_product_reservation_'.$reservation->getId(), $request);

        try {
            $this->reservationManager->withdraw($reservation, $this->getAdmin(), (string) $request->request->get('admin_note'));
            $this->entityManager->flush();
            $this->addFlash('success', 'L’achat a été marqué comme retiré. L’article est vendu et retiré de la boutique.');
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_admin_product_index');
    }

    #[Route('/admin/promotions/reservations/{id}/annuler', name: 'app_admin_product_reservation_cancel', methods: ['POST'])]
    public function cancelReservation(ProductReservation $reservation, Request $request): RedirectResponse
    {
        $this->denyUnlessValidCsrf('admin_product_reservation_'.$reservation->getId(), $request);

        try {
            $this->reservationManager->cancelByAdmin($reservation, $this->getAdmin(), (string) $request->request->get('admin_note'));
            $this->addFlash('success', 'La réservation boutique a été annulée et la fidélité utilisée a été remboursée.');
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_admin_product_index');
    }

    private function applyProductData(Product $product, Request $request): void
    {
        $name = trim((string) $request->request->get('name'));
        $description = trim((string) $request->request->get('description'));
        $normalPriceCents = $this->parseMoneyCents((string) $request->request->get('normal_price'));
        $promotionalPriceCents = $this->parseMoneyCents((string) $request->request->get('promotional_price'));

        if ($name === '') {
            throw new \InvalidArgumentException('Indiquez le nom de l’article.');
        }

        if ($description === '') {
            throw new \InvalidArgumentException('Ajoutez une description pour l’article.');
        }

        if ($promotionalPriceCents > $normalPriceCents) {
            throw new \InvalidArgumentException('Le prix promo doit rester inférieur ou égal au prix normal.');
        }

        $product
            ->setName($name)
            ->setDescription($description)
            ->setNormalPriceCents($normalPriceCents)
            ->setPromotionalPriceCents($promotionalPriceCents)
            ->setCustomAttributes($this->parseCustomAttributes($request))
            ->setIsActive($request->request->getBoolean('is_active', true));

        $this->handlePhotoUpload($product, $request);
    }

    private function parseMoneyCents(string $value): int
    {
        $normalized = str_replace(["\xc2\xa0", ' '], '', trim($value));
        $normalized = str_replace(',', '.', $normalized);

        if ($normalized === '' || !is_numeric($normalized)) {
            throw new \InvalidArgumentException('Indiquez un prix valide.');
        }

        $cents = (int) round(((float) $normalized) * 100);

        if ($cents <= 0) {
            throw new \InvalidArgumentException('Le prix doit être supérieur à zéro.');
        }

        return $cents;
    }

    /** @return list<array{label: string, value: string}> */
    private function parseCustomAttributes(Request $request): array
    {
        $payload = $request->request->all();
        $labels = $payload['custom_label'] ?? [];
        $values = $payload['custom_value'] ?? [];
        $labels = is_array($labels) ? $labels : [];
        $values = is_array($values) ? $values : [];
        $attributes = [];

        foreach ($labels as $index => $label) {
            $attributes[] = [
                'label' => (string) $label,
                'value' => (string) ($values[$index] ?? ''),
            ];
        }

        return $attributes;
    }

    private function handlePhotoUpload(Product $product, Request $request): void
    {
        $file = $request->files->get('photo');

        if (!$file instanceof UploadedFile || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return;
        }

        if (!$file->isValid()) {
            throw new \InvalidArgumentException('La photo n’a pas pu être envoyée correctement.');
        }

        $extension = mb_strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());

        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            throw new \InvalidArgumentException('La photo doit être une image JPG, PNG, WebP ou GIF.');
        }

        $directory = $this->projectDir.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'promotions';

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \InvalidArgumentException('Impossible de créer le dossier des photos promotionnelles.');
        }

        $filename = bin2hex(random_bytes(12)).'.'.$extension;
        $file->move($directory, $filename);
        $product->setPhotoPath('/uploads/promotions/'.$filename);
    }

    private function getAdmin(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function denyUnlessValidCsrf(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }
}
