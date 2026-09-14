<?php

declare(strict_types=1);

namespace App\Controller\Api\Admin;

use App\Entity\RecruitmentApplication;
use App\Entity\RecruitmentPosition;
use App\Repository\RecruitmentApplicationRepository;
use App\Repository\RecruitmentPositionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class RecruitmentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RecruitmentApplicationRepository $applicationRepository,
        private readonly RecruitmentPositionRepository $positionRepository,
    ) {
    }

    #[Route('/api/admin/recrutement/{id}', name: 'app_api_admin_recruitment_update', methods: ['POST'])]
    public function update(RecruitmentApplication $application, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('admin_recruitment_'.$application->getId(), (string) $request->request->get('_token'))) {
            return $this->validationError(['Jeton de sécurité invalide. Rechargez la page avant de réessayer.']);
        }

        $status = (string) $request->request->get('status');

        if (!isset(RecruitmentApplication::STATUS_LABELS[$status])) {
            return $this->validationError(['Choisissez un statut valide.']);
        }

        $application
            ->setStatus($status)
            ->setAdminNote((string) $request->request->get('admin_note'));

        $this->entityManager->flush();

        return $this->success('La candidature a été mise à jour.', $request);
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

    private function success(string $message, Request $request): JsonResponse
    {
        $search = (string) $request->request->get('_list_search', '');
        $status = (string) $request->request->get('_list_status', '');
        $applications = $this->applicationRepository->findForAdmin($search, $status);

        return new JsonResponse([
            'success' => true,
            'message' => $message,
            'fragments' => [
                [
                    'selector' => '#recruitment-table-body',
                    'html' => $this->renderView('admin/recruitment/partial/_table_body.html.twig', [
                        'applications' => $applications,
                    ]),
                ],
                [
                    'selector' => '#recruitment-modal-stack',
                    'html' => $this->renderView('admin/recruitment/partial/_modal_stack.html.twig', [
                        'applications' => $applications,
                        'positions' => $this->positionRepository->findForAdmin(),
                        'position_categories' => RecruitmentPosition::CATEGORY_CHOICES,
                        'status_labels' => RecruitmentApplication::STATUS_LABELS,
                        'search' => $search,
                        'status' => $status,
                    ]),
                ],
            ],
        ]);
    }
}
