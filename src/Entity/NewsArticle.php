<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NewsArticleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: NewsArticleRepository::class)]
#[ORM\Table(name: 'news_article')]
#[ORM\Index(name: 'IDX_NEWS_ACTIVE_PUBLISHED', columns: ['is_active', 'published_at'])]
class NewsArticle
{
    public const MEDIA_IMAGE = 'image';
    public const MEDIA_VIDEO = 'video';

    public const MEDIA_LABELS = [
        self::MEDIA_IMAGE => 'Photo',
        self::MEDIA_VIDEO => 'Vidéo YouTube',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank(message: 'Indiquez le titre de l’actualité.')]
    #[Assert\Length(max: 160, maxMessage: 'Le titre ne doit pas dépasser 160 caractères.')]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Ajoutez le contenu de l’actualité.')]
    #[Assert\Length(min: 20, minMessage: 'Le contenu doit contenir au moins 20 caractères.')]
    private string $content = '';

    #[ORM\Column(length: 16)]
    #[Assert\Choice(choices: [self::MEDIA_IMAGE, self::MEDIA_VIDEO], message: 'Choisissez une photo ou une vidéo YouTube.')]
    private string $mediaType = self::MEDIA_IMAGE;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $imagePath = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $youtubeUrl = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $publishedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->publishedAt = $now;
        $this->expiresAt = $now->modify('+1 month');
        $this->createdAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = trim($title);
        $this->touch();

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = trim($content);
        $this->touch();

        return $this;
    }

    public function getSummary(int $length = 180): string
    {
        $plainText = trim((string) preg_replace('/\s+/u', ' ', strip_tags($this->content)));

        return mb_strlen($plainText) > $length ? rtrim(mb_substr($plainText, 0, $length - 1)).'…' : $plainText;
    }

    public function getMediaType(): string
    {
        return $this->mediaType;
    }

    public function setMediaType(string $mediaType): self
    {
        $this->mediaType = $mediaType;
        $this->touch();

        return $this;
    }

    public function getMediaLabel(): string
    {
        return self::MEDIA_LABELS[$this->mediaType] ?? 'Média';
    }

    public function isImage(): bool
    {
        return $this->mediaType === self::MEDIA_IMAGE;
    }

    public function isVideo(): bool
    {
        return $this->mediaType === self::MEDIA_VIDEO;
    }

    public function getImagePath(): ?string
    {
        return $this->imagePath;
    }

    public function setImagePath(?string $imagePath): self
    {
        $imagePath = $imagePath !== null ? trim($imagePath) : null;
        $this->imagePath = $imagePath !== '' ? $imagePath : null;
        $this->touch();

        return $this;
    }

    public function getYoutubeUrl(): ?string
    {
        return $this->youtubeUrl;
    }

    public function setYoutubeUrl(?string $youtubeUrl): self
    {
        $youtubeUrl = $youtubeUrl !== null ? trim($youtubeUrl) : null;
        $this->youtubeUrl = $youtubeUrl !== '' ? $youtubeUrl : null;
        $this->touch();

        return $this;
    }

    public function getYoutubeVideoId(): ?string
    {
        return self::extractYoutubeVideoId($this->youtubeUrl);
    }

    public function getYoutubeThumbnailUrl(): ?string
    {
        $videoId = $this->getYoutubeVideoId();

        return $videoId !== null ? 'https://i.ytimg.com/vi/'.$videoId.'/hqdefault.jpg' : null;
    }

    public function getYoutubeEmbedUrl(): ?string
    {
        $videoId = $this->getYoutubeVideoId();

        return $videoId !== null ? 'https://www.youtube-nocookie.com/embed/'.$videoId : null;
    }

    public static function extractYoutubeVideoId(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $parts = parse_url(trim($url));

        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }

        $host = mb_strtolower(preg_replace('/^www\./', '', (string) $parts['host']) ?? '');
        $path = trim((string) ($parts['path'] ?? ''), '/');
        $candidate = null;

        if ($host === 'youtu.be') {
            $candidate = explode('/', $path)[0] ?? null;
        } elseif (in_array($host, ['youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com'], true)) {
            if ($path === 'watch') {
                parse_str((string) ($parts['query'] ?? ''), $query);
                $candidate = $query['v'] ?? null;
            } elseif (preg_match('#^(?:embed|shorts|live)/([^/]+)#', $path, $matches) === 1) {
                $candidate = $matches[1];
            }
        }

        return is_string($candidate) && preg_match('/^[A-Za-z0-9_-]{6,20}$/', $candidate) === 1
            ? $candidate
            : null;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        $this->touch();

        return $this;
    }

    public function getPublishedAt(): \DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(\DateTimeImmutable $publishedAt): self
    {
        $this->publishedAt = $publishedAt;
        $this->touch();

        return $this;
    }

    public function isPublished(): bool
    {
        $now = new \DateTimeImmutable();

        return $this->isActive && $this->publishedAt <= $now && !$this->hasExpired($now);
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt ?? $this->publishedAt->modify('+1 month');
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;
        $this->touch();

        return $this;
    }

    public function hasExpired(?\DateTimeImmutable $at = null): bool
    {
        return $this->getExpiresAt() <= ($at ?? new \DateTimeImmutable());
    }

    public function extendOneMonth(): self
    {
        $now = new \DateTimeImmutable();
        $this->expiresAt = $now->modify('+1 month');
        $this->isActive = true;
        $this->touch();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
