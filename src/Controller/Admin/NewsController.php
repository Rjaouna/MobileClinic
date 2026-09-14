<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\NewsArticle;
use App\Repository\NewsArticleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[IsGranted('ROLE_ADMIN')]
final class NewsController extends AbstractController
{
    public function __construct(
        private readonly NewsArticleRepository $newsArticleRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/admin/actualites', name: 'app_admin_news_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/news/index.html.twig', $this->buildViewData());
    }

    #[Route('/admin/actualites', name: 'app_admin_news_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse|RedirectResponse
    {
        $this->denyUnlessValidCsrf('admin_news_create', $request);
        $article = new NewsArticle();

        try {
            $this->applyArticleData($article, $request);
            $this->entityManager->persist($article);
            $this->entityManager->flush();

            return $this->success($request, 'L’actualité a été créée.');
        } catch (\InvalidArgumentException $exception) {
            return $this->failure($request, $exception->getMessage());
        }
    }

    #[Route('/admin/actualites/{id}/modifier', name: 'app_admin_news_update', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function update(NewsArticle $article, Request $request): JsonResponse|RedirectResponse
    {
        $this->denyUnlessValidCsrf('admin_news_'.$article->getId(), $request);

        try {
            $obsoleteImage = $this->applyArticleData($article, $request);
            $this->entityManager->flush();
            $this->removeUploadedImage($obsoleteImage);

            return $this->success($request, 'L’actualité a été mise à jour.');
        } catch (\InvalidArgumentException $exception) {
            return $this->failure($request, $exception->getMessage());
        }
    }

    #[Route('/admin/actualites/{id}/visibilite', name: 'app_admin_news_toggle', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function toggle(NewsArticle $article, Request $request): JsonResponse|RedirectResponse
    {
        $this->denyUnlessValidCsrf('admin_news_'.$article->getId(), $request);
        $article->setIsActive(!$article->isActive());
        $this->entityManager->flush();

        return $this->success(
            $request,
            $article->isActive() ? 'L’actualité est de nouveau visible.' : 'L’actualité a été masquée.',
        );
    }

    #[Route('/admin/actualites/{id}/prolonger', name: 'app_admin_news_extend', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function extend(NewsArticle $article, Request $request): JsonResponse|RedirectResponse
    {
        $this->denyUnlessValidCsrf('admin_news_extend_'.$article->getId(), $request);
        $article->extendOneMonth();
        $this->entityManager->flush();

        return $this->success($request, 'L’actualité est prolongée d’un mois et de nouveau visible.');
    }

    #[Route('/admin/actualites/{id}/supprimer', name: 'app_admin_news_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function delete(NewsArticle $article, Request $request): JsonResponse|RedirectResponse
    {
        $this->denyUnlessValidCsrf('admin_news_delete_'.$article->getId(), $request);
        $imagePath = $article->getImagePath();
        $this->entityManager->remove($article);
        $this->entityManager->flush();
        $this->removeUploadedImage($imagePath);

        return $this->success($request, 'L’actualité a été supprimée.');
    }

    /** @return array<string, mixed> */
    private function buildViewData(): array
    {
        $articles = $this->newsArticleRepository->findForAdmin();

        return [
            'articles' => $articles,
            'media_labels' => NewsArticle::MEDIA_LABELS,
            'stats' => [
                'total' => count($articles),
                'published' => count(array_filter($articles, static fn (NewsArticle $article): bool => $article->isPublished())),
                'expired' => count(array_filter($articles, static fn (NewsArticle $article): bool => $article->isActive() && $article->hasExpired())),
                'videos' => count(array_filter($articles, static fn (NewsArticle $article): bool => $article->isVideo())),
            ],
        ];
    }

    private function applyArticleData(NewsArticle $article, Request $request): ?string
    {
        $oldImagePath = $article->getImagePath();
        $previousPublishedAt = $article->getPublishedAt();
        $isNew = $article->getId() === null;
        $previousPublishedAt = $article->getPublishedAt();
        $isNewArticle = $article->getId() === null;
        $mediaType = (string) $request->request->get('media_type', NewsArticle::MEDIA_IMAGE);
        $publishedAtValue = trim((string) $request->request->get('published_at'));

        if (!array_key_exists($mediaType, NewsArticle::MEDIA_LABELS)) {
            throw new \InvalidArgumentException('Choisissez une photo ou une vidéo YouTube.');
        }

        try {
            $publishedAt = $publishedAtValue !== ''
                ? new \DateTimeImmutable($publishedAtValue)
                : new \DateTimeImmutable();
        } catch (\Exception) {
            throw new \InvalidArgumentException('Indiquez une date de mise en ligne valide.');
        }

        $article
            ->setTitle((string) $request->request->get('title'))
            ->setContent((string) $request->request->get('content'))
            ->setMediaType($mediaType)
            ->setPublishedAt($publishedAt)
            ->setIsActive($request->request->getBoolean('is_active'));

        if ($isNew || $previousPublishedAt != $publishedAt) {
            $article->setExpiresAt($publishedAt->modify('+1 month'));
        }

        if ($isNewArticle || $previousPublishedAt != $publishedAt) {
            $article->setExpiresAt($publishedAt->modify('+1 month'));
        }

        if ($mediaType === NewsArticle::MEDIA_VIDEO) {
            $youtubeUrl = trim((string) $request->request->get('youtube_url'));

            if (NewsArticle::extractYoutubeVideoId($youtubeUrl) === null) {
                throw new \InvalidArgumentException('Indiquez un lien YouTube valide. Les vidéos ne sont jamais importées sur le site.');
            }

            $article
                ->setYoutubeUrl($youtubeUrl)
                ->setImagePath(null);
        } else {
            $article->setYoutubeUrl(null);
        }

        $errors = [];

        foreach ($this->validator->validate($article) as $violation) {
            $errors[] = $violation->getMessage();
        }

        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', array_unique($errors)));
        }

        if ($mediaType === NewsArticle::MEDIA_IMAGE) {
            $newImagePath = $this->handleImageUpload($request);

            if ($newImagePath !== null) {
                $article->setImagePath($newImagePath);
            }

            if ($article->getImagePath() === null) {
                throw new \InvalidArgumentException('Ajoutez une photo pour cette actualité.');
            }
        }

        return $oldImagePath !== null && $oldImagePath !== $article->getImagePath() ? $oldImagePath : null;
    }

    private function handleImageUpload(Request $request): ?string
    {
        $file = $request->files->get('image');

        if (!$file instanceof UploadedFile || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if (!$file->isValid()) {
            throw new \InvalidArgumentException('La photo n’a pas pu être envoyée correctement.');
        }

        if (($file->getSize() ?: 0) > 6 * 1024 * 1024) {
            throw new \InvalidArgumentException('La photo ne doit pas dépasser 6 Mo.');
        }

        $extension = mb_strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());

        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) || @getimagesize($file->getPathname()) === false) {
            throw new \InvalidArgumentException('La photo doit être une image JPG, PNG ou WebP valide.');
        }

        $directory = $this->getUploadDirectory();

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \InvalidArgumentException('Impossible de créer le dossier des actualités.');
        }

        $filename = 'actualite-'.bin2hex(random_bytes(12)).'.'.($extension === 'jpeg' ? 'jpg' : $extension);
        $file->move($directory, $filename);

        return '/uploads/news/'.$filename;
    }

    private function removeUploadedImage(?string $imagePath): void
    {
        if ($imagePath === null || !str_starts_with($imagePath, '/uploads/news/')) {
            return;
        }

        $filename = basename($imagePath);
        $filePath = $this->getUploadDirectory().DIRECTORY_SEPARATOR.$filename;

        if (is_file($filePath)) {
            @unlink($filePath);
        }
    }

    private function getUploadDirectory(): string
    {
        return $this->projectDir.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'news';
    }

    private function success(Request $request, string $message): JsonResponse|RedirectResponse
    {
        if (!$request->isXmlHttpRequest()) {
            $this->addFlash('success', $message);

            return $this->redirectToRoute(
                $request->request->get('_return_to') === 'dashboard' ? 'app_admin_dashboard' : 'app_admin_news_index',
            );
        }

        $viewData = $this->buildViewData();

        return $this->json([
            'success' => true,
            'message' => $message,
            'fragments' => [
                [
                    'selector' => '#news-stats-region',
                    'html' => $this->renderView('admin/news/partial/_stats.html.twig', $viewData),
                ],
                [
                    'selector' => '#news-list-region',
                    'html' => $this->renderView('admin/news/partial/_list.html.twig', $viewData),
                ],
                [
                    'selector' => '#news-modal-stack',
                    'html' => $this->renderView('admin/news/partial/_modal_stack.html.twig', $viewData),
                ],
            ],
        ]);
    }

    private function failure(Request $request, string $message): JsonResponse|RedirectResponse
    {
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => false, 'errors' => [$message]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('error', $message);

        return $this->redirectToRoute(
            $request->request->get('_return_to') === 'dashboard' ? 'app_admin_dashboard' : 'app_admin_news_index',
        );
    }

    private function denyUnlessValidCsrf(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }
}
