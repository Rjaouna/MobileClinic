<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\NewsArticle;
use App\Entity\User;
use App\Repository\NewsArticleRepository;
use App\Tests\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class NewsFlowTest extends WebTestCase
{
    use DatabaseResetTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->entityManager = $this->resetDoctrineSchema();
    }

    #[Test]
    public function adminCanCreateAndHideAYoutubeNewsArticle(): void
    {
        $admin = $this->user('news-admin@symaclinic.fr', '+33605000701', ['ROLE_ADMIN']);
        $this->entityManager->flush();
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/actualites');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('table.data-table');
        self::assertSelectorExists('dialog#news-add-modal.modal--fullscreen');
        self::assertSelectorExists('form[data-news-media-form]');

        $token = $crawler->filter('form[action="/admin/actualites"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/actualites', [
            '_token' => $token,
            'title' => 'Les coulisses de notre atelier en vidéo',
            'content' => 'Découvrez comment notre équipe organise le diagnostic et le contrôle qualité de chaque appareil confié à la boutique.',
            'media_type' => NewsArticle::MEDIA_VIDEO,
            'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'published_at' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d\TH:i'),
            'is_active' => '1',
        ]);

        self::assertResponseRedirects('/admin/actualites');
        $article = static::getContainer()->get(NewsArticleRepository::class)->findOneBy([
            'title' => 'Les coulisses de notre atelier en vidéo',
        ]);
        self::assertInstanceOf(NewsArticle::class, $article);
        self::assertTrue($article->isVideo());
        self::assertSame('dQw4w9WgXcQ', $article->getYoutubeVideoId());
        self::assertSame(
            $article->getPublishedAt()->modify('+1 month')->format('Y-m-d H:i'),
            $article->getExpiresAt()->format('Y-m-d H:i'),
        );

        $this->client->request('GET', '/actualites');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Les coulisses de notre atelier en vidéo', $this->content());

        $crawler = $this->client->request('GET', '/admin/actualites');
        $toggleToken = $crawler->filter(sprintf(
            'form[action="/admin/actualites/%d/visibilite"] input[name="_token"]',
            $article->getId(),
        ))->attr('value');
        $this->client->request(
            'POST',
            sprintf('/admin/actualites/%d/visibilite', $article->getId()),
            ['_token' => $toggleToken],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );
        self::assertResponseIsSuccessful();
        $payload = json_decode($this->content(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['success']);
        self::assertCount(3, $payload['fragments']);
        self::assertSame('#news-stats-region', $payload['fragments'][0]['selector']);

        $this->client->request('GET', '/actualites');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Les coulisses de notre atelier en vidéo', $this->content());

        $this->client->request('GET', sprintf('/actualites/%d', $article->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function homepageShowsOnlyTheFourLatestPublishedArticles(): void
    {
        for ($index = 1; $index <= 6; ++$index) {
            $publishedAt = new \DateTimeImmutable(sprintf('-%d day', 6 - $index));
            $article = (new NewsArticle())
                ->setTitle('Actualité test '.$index)
                ->setContent('Une information suffisamment détaillée pour vérifier le bloc des actualités de la page d’accueil.')
                ->setMediaType(NewsArticle::MEDIA_IMAGE)
                ->setImagePath('https://images.unsplash.com/photo-1736173155811-e8142fd553ee')
                ->setPublishedAt($publishedAt)
                ->setExpiresAt($publishedAt->modify('+1 month'))
                ->setIsActive(true);
            $this->entityManager->persist($article);
        }

        $this->entityManager->flush();
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(4, '.news-grid--home .news-card');
        self::assertStringContainsString('Actualité test 6', $this->content());
        self::assertStringNotContainsString('Actualité test 1', $this->content());
    }

    #[Test]
    public function expiredArticleNotifiesAdminAndCanBeExtended(): void
    {
        $admin = $this->user('news-reminder@symaclinic.fr', '+33605000702', ['ROLE_ADMIN']);
        $article = (new NewsArticle())
            ->setTitle('Une actualité arrivée à échéance')
            ->setContent('Cette publication permet de vérifier la notification et la prolongation depuis le dashboard administrateur.')
            ->setMediaType(NewsArticle::MEDIA_IMAGE)
            ->setImagePath('https://images.unsplash.com/photo-1709102884400-b50ca1a12bc3')
            ->setPublishedAt(new \DateTimeImmutable('-2 months'))
            ->setExpiresAt(new \DateTimeImmutable('-1 month'))
            ->setIsActive(true);
        $this->entityManager->persist($article);
        $this->entityManager->flush();
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Actualités à renouveler', $this->content());
        self::assertStringContainsString('Une actualité arrivée à échéance', $this->content());

        $token = $crawler->filter(sprintf(
            'form[action="/admin/actualites/%d/prolonger"] input[name="_token"]',
            $article->getId(),
        ))->attr('value');
        $this->client->request('POST', sprintf('/admin/actualites/%d/prolonger', $article->getId()), [
            '_token' => $token,
            '_return_to' => 'dashboard',
        ]);
        self::assertResponseRedirects('/admin');

        $article = static::getContainer()->get(NewsArticleRepository::class)->find($article->getId());
        self::assertInstanceOf(NewsArticle::class, $article);
        self::assertFalse($article->hasExpired());
        self::assertTrue($article->isActive());

        $this->client->request('GET', '/actualites');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Une actualité arrivée à échéance', $this->content());
    }

    /** @param list<string> $roles */
    private function user(string $email, string $phone, array $roles = ['ROLE_USER']): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setPhone($phone)
            ->setRoles($roles)
            ->setIsActive(true);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, $email));
        $this->entityManager->persist($user);

        return $user;
    }

    private function content(): string
    {
        return $this->client->getResponse()->getContent() ?: '';
    }
}
