<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\RecruitmentApplication;
use App\Entity\RecruitmentPosition;
use App\Repository\RecruitmentApplicationRepository;
use App\Repository\RecruitmentPositionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[IsGranted('ROLE_ADMIN')]
final class RecruitmentController extends AbstractController
{
    public function __construct(
        private readonly RecruitmentApplicationRepository $applicationRepository,
        private readonly RecruitmentPositionRepository $positionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/admin/recrutement', name: 'app_admin_recruitment_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search = (string) $request->query->get('q', '');
        $status = (string) $request->query->get('status', '');
        $applications = $this->applicationRepository->findForAdmin($search, $status);

        return $this->render('admin/recruitment/index.html.twig', [
            'applications' => $applications,
            'positions' => $this->positionRepository->findForAdmin(),
            'position_categories' => RecruitmentPosition::CATEGORY_CHOICES,
            'status_labels' => RecruitmentApplication::STATUS_LABELS,
            'search' => $search,
            'status' => $status,
            'stats' => [
                'total' => $this->applicationRepository->countAllApplications(),
                'open' => $this->applicationRepository->countOpen(),
                'visible' => count($applications),
                'active_positions' => $this->positionRepository->countActive(),
            ],
        ]);
    }

    #[Route('/admin/recrutement/postes', name: 'app_admin_recruitment_position_create', methods: ['POST'])]
    public function createPosition(Request $request): RedirectResponse
    {
        $this->denyUnlessValidCsrf('admin_recruitment_position_create', $request);
        $position = new RecruitmentPosition();

        try {
            $this->applyPositionData($position, $request);
            $this->entityManager->persist($position);
            $this->entityManager->flush();
            $this->addFlash('success', 'Le poste a été créé et ajouté aux opportunités.');
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_admin_recruitment_index', ['_fragment' => 'recruitment-positions-title']);
    }

    #[Route('/admin/recrutement/postes/{id}/modifier', name: 'app_admin_recruitment_position_update', methods: ['POST'])]
    public function updatePosition(RecruitmentPosition $position, Request $request): RedirectResponse
    {
        $this->denyUnlessValidCsrf('admin_recruitment_position_'.$position->getId(), $request);

        try {
            $this->applyPositionData($position, $request);
            $this->entityManager->flush();
            $this->addFlash('success', 'Le poste a été mis à jour.');
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_admin_recruitment_index', ['_fragment' => 'recruitment-positions-title']);
    }

    #[Route('/admin/recrutement/postes/{id}/visibilite', name: 'app_admin_recruitment_position_toggle', methods: ['POST'])]
    public function togglePosition(RecruitmentPosition $position, Request $request): RedirectResponse
    {
        $this->denyUnlessValidCsrf('admin_recruitment_position_'.$position->getId(), $request);
        $position->setIsActive(!$position->isActive());
        $this->entityManager->flush();
        $this->addFlash('success', $position->isActive() ? 'Le poste est de nouveau visible.' : 'Le poste est masqué de la page recrutement.');

        return $this->redirectToRoute('app_admin_recruitment_index', ['_fragment' => 'recruitment-positions-title']);
    }

    private function applyPositionData(RecruitmentPosition $position, Request $request): void
    {
        $requestData = $request->request->all();
        $highlights = $requestData['highlights'] ?? [];
        $title = trim((string) $request->request->get('title'));

        if ($this->positionRepository->titleExists($title, $position->getId())) {
            throw new \InvalidArgumentException('Un poste avec ce titre existe déjà.');
        }

        $position
            ->setTitle($title)
            ->setCategory((string) $request->request->get('category'))
            ->setDescription((string) $request->request->get('description'))
            ->setRhythm((string) $request->request->get('rhythm'))
            ->setHighlights(is_array($highlights) ? array_values($highlights) : [])
            ->setIsActive($request->request->getBoolean('is_active'));

        $errors = [];

        foreach ($this->validator->validate($position) as $violation) {
            $errors[] = $violation->getMessage();
        }

        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', array_unique($errors)));
        }
    }

    private function denyUnlessValidCsrf(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }
}
