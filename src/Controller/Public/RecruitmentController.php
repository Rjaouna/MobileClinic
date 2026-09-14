<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\RecruitmentApplication;
use App\Entity\RecruitmentPosition;
use App\Entity\User;
use App\Repository\RecruitmentPositionRepository;
use App\Service\GeneralSettingManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class RecruitmentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly GeneralSettingManager $generalSettingManager,
        private readonly RecruitmentPositionRepository $positionRepository,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/recrutement', name: 'app_public_recruitment_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $selectedPosition = $this->resolveSelectedPosition((string) $request->query->get('position', ''));

        return $this->render('public/recruitment/index.html.twig', $this->getViewData($selectedPosition));
    }

    #[Route('/recrutement/postuler', name: 'app_public_recruitment_apply', methods: ['POST'])]
    public function apply(Request $request): JsonResponse|RedirectResponse
    {
        if (!$this->isCsrfTokenValid('public_recruitment_apply', (string) $request->request->get('_token'))) {
            return $this->applicationError(['Jeton de sécurité invalide. Rechargez la page avant de réessayer.'], $request);
        }

        $positionChoice = (string) $request->request->get('position_id');
        $position = $this->resolveApplicationPosition($positionChoice);

        if ($positionChoice !== 'spontaneous' && !$position instanceof RecruitmentPosition) {
            return $this->applicationError(['Choisissez un poste actuellement disponible.'], $request);
        }

        $desiredPosition = $position?->getTitle() ?? RecruitmentApplication::SPONTANEOUS_POSITION;
        $application = (new RecruitmentApplication())
            ->setFirstName((string) $request->request->get('first_name'))
            ->setLastName((string) $request->request->get('last_name'))
            ->setEmail((string) $request->request->get('email'))
            ->setPhone((string) $request->request->get('phone'))
            ->setDesiredPosition($desiredPosition)
            ->setPosition($position)
            ->setAvailability((string) $request->request->get('availability'))
            ->setExperienceLevel((string) $request->request->get('experience_level'))
            ->setMessage((string) $request->request->get('message'));

        $errors = $this->validateApplication($application);

        if ($errors !== []) {
            return $this->applicationError($errors, $request);
        }

        try {
            $application->setResumePath($this->handleResumeUpload($request));
        } catch (\InvalidArgumentException $exception) {
            return $this->applicationError([$exception->getMessage()], $request);
        }

        $this->entityManager->persist($application);
        $this->entityManager->flush();

        if (!$request->isXmlHttpRequest()) {
            $this->addFlash('success', 'Votre candidature a bien été envoyée. Notre équipe vous recontactera si votre profil correspond au besoin.');

            return $this->redirectToRoute('app_public_recruitment_index');
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'Votre candidature a bien été envoyée.',
            'fragments' => [
                [
                    'selector' => '#recruitment-application-feedback',
                    'html' => $this->renderView('public/recruitment/partial/_application_success.html.twig', [
                        'application' => $application,
                    ]),
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function getViewData(string $selectedPosition = ''): array
    {
        $candidate = $this->getUser();

        return [
            'setting' => $this->generalSettingManager->getSetting(),
            'positions' => $this->positionRepository->findActivePositions(),
            'availabilities' => RecruitmentApplication::AVAILABILITY_CHOICES,
            'experiences' => RecruitmentApplication::EXPERIENCE_CHOICES,
            'selected_position' => $selectedPosition,
            'open_positions' => $this->positionRepository->findActivePositions(),
            'candidate' => $candidate instanceof User ? $candidate : null,
            'recruitment_steps' => [
                [
                    'title' => 'Votre candidature',
                    'text' => 'Vous nous présentez votre parcours, vos disponibilités et le métier qui vous intéresse.',
                ],
                [
                    'title' => 'Un premier échange',
                    'text' => 'Nous échangeons simplement sur votre expérience, vos attentes et notre façon de travailler.',
                ],
                [
                    'title' => 'Une rencontre métier',
                    'text' => 'Un entretien ou une mise en situation permet de confirmer l’envie de travailler ensemble.',
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function validateApplication(RecruitmentApplication $application): array
    {
        $errors = [];

        foreach ($this->validator->validate($application) as $violation) {
            $errors[] = $violation->getMessage();
        }

        if (!in_array($application->getAvailability(), RecruitmentApplication::AVAILABILITY_CHOICES, true)) {
            $errors[] = 'Choisissez une disponibilité.';
        }

        if (!in_array($application->getExperienceLevel(), RecruitmentApplication::EXPERIENCE_CHOICES, true)) {
            $errors[] = 'Choisissez votre niveau d’expérience.';
        }

        if (preg_match('/^\+?\d[\d\s().-]{7,24}$/', $application->getPhone()) !== 1) {
            $errors[] = 'Indiquez un numéro de téléphone valide.';
        }

        return array_values(array_unique($errors));
    }

    private function resolveSelectedPosition(string $positionChoice): string
    {
        if ($positionChoice === 'spontaneous') {
            return 'spontaneous';
        }

        if (!ctype_digit($positionChoice)) {
            return '';
        }

        $position = $this->positionRepository->findActive((int) $positionChoice);

        return $position instanceof RecruitmentPosition ? (string) $position->getId() : '';
    }

    private function resolveApplicationPosition(string $positionChoice): ?RecruitmentPosition
    {
        if (!ctype_digit($positionChoice)) {
            return null;
        }

        return $this->positionRepository->findActive((int) $positionChoice);
    }

    private function handleResumeUpload(Request $request): ?string
    {
        $file = $request->files->get('resume');

        if (!$file instanceof UploadedFile || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if (!$file->isValid()) {
            throw new \InvalidArgumentException('Le CV n’a pas pu être envoyé correctement.');
        }

        if ($file->getSize() !== false && $file->getSize() > 5 * 1024 * 1024) {
            throw new \InvalidArgumentException('Le CV ne doit pas dépasser 5 Mo.');
        }

        $extension = mb_strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());

        if (!in_array($extension, ['pdf', 'doc', 'docx', 'odt'], true)) {
            throw new \InvalidArgumentException('Le CV doit être au format PDF, DOC, DOCX ou ODT.');
        }

        $directory = $this->projectDir.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'recruitment';

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \InvalidArgumentException('Impossible de créer le dossier des CV.');
        }

        $filename = 'candidature-'.bin2hex(random_bytes(10)).'.'.$extension;
        $file->move($directory, $filename);

        return '/uploads/recruitment/'.$filename;
    }

    /**
     * @param list<string> $errors
     */
    private function applicationError(array $errors, Request $request): JsonResponse|RedirectResponse
    {
        if (!$request->isXmlHttpRequest()) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirectToRoute('app_public_recruitment_index');
        }

        return new JsonResponse([
            'success' => false,
            'message' => 'La candidature contient des erreurs.',
            'errors' => $errors,
        ], 422);
    }
}
