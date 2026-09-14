<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\NewsArticle;
use App\Repository\NewsArticleRepository;
use App\Service\GeneralSettingManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class NewsController extends AbstractController
{
    public function __construct(
        private readonly NewsArticleRepository $newsArticleRepository,
        private readonly GeneralSettingManager $generalSettingManager,
    ) {
    }

    #[Route('/actualites', name: 'app_public_news_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('public/news/index.html.twig', [
            'articles' => $this->newsArticleRepository->findPublished(),
            'setting' => $this->generalSettingManager->getSetting(),
        ]);
    }

    #[Route('/actualites/{id}', name: 'app_public_news_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(NewsArticle $article): Response
    {
        if (!$article->isPublished()) {
            throw $this->createNotFoundException('Cette actualité n’est pas disponible.');
        }

        return $this->render('public/news/show.html.twig', [
            'article' => $article,
            'latest_articles' => array_values(array_filter(
                $this->newsArticleRepository->findPublished(4),
                static fn (NewsArticle $candidate): bool => $candidate->getId() !== $article->getId(),
            )),
            'setting' => $this->generalSettingManager->getSetting(),
        ]);
    }
}
