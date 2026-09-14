<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'product')]
#[ORM\Index(name: 'IDX_PRODUCT_ACTIVE_CREATED', columns: ['is_active', 'created_at'])]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 140)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 140)]
    private string $name = '';

    #[ORM\Column]
    #[Assert\Positive]
    private int $normalPriceCents = 0;

    #[ORM\Column]
    #[Assert\Positive]
    private int $promotionalPriceCents = 0;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $description = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoPath = null;

    /** @var list<array{label: string, value: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $customAttributes = [];

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $soldAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = trim($name);
        $this->touch();

        return $this;
    }

    public function getNormalPriceCents(): int
    {
        return $this->normalPriceCents;
    }

    public function setNormalPriceCents(int $normalPriceCents): self
    {
        $this->normalPriceCents = max(0, $normalPriceCents);
        $this->touch();

        return $this;
    }

    public function getPromotionalPriceCents(): int
    {
        return $this->promotionalPriceCents;
    }

    public function setPromotionalPriceCents(int $promotionalPriceCents): self
    {
        $this->promotionalPriceCents = max(0, $promotionalPriceCents);
        $this->touch();

        return $this;
    }

    public function getDiscountCents(): int
    {
        return max(0, $this->normalPriceCents - $this->promotionalPriceCents);
    }

    public function getDiscountPercent(): int
    {
        if ($this->normalPriceCents <= 0 || $this->getDiscountCents() <= 0) {
            return 0;
        }

        return (int) round(($this->getDiscountCents() / $this->normalPriceCents) * 100);
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = trim($description);
        $this->touch();

        return $this;
    }

    public function getPhotoPath(): ?string
    {
        return $this->photoPath;
    }

    public function setPhotoPath(?string $photoPath): self
    {
        $photoPath = $photoPath !== null ? trim($photoPath) : null;
        $this->photoPath = $photoPath !== '' ? $photoPath : null;
        $this->touch();

        return $this;
    }

    /** @return list<array{label: string, value: string}> */
    public function getCustomAttributes(): array
    {
        return $this->customAttributes;
    }

    /** @param list<array{label?: string|null, value?: string|null}> $customAttributes */
    public function setCustomAttributes(array $customAttributes): self
    {
        $cleanAttributes = [];

        foreach ($customAttributes as $attribute) {
            $label = trim((string) ($attribute['label'] ?? ''));
            $value = trim((string) ($attribute['value'] ?? ''));

            if ($label === '' || $value === '') {
                continue;
            }

            $cleanAttributes[] = [
                'label' => mb_substr($label, 0, 80),
                'value' => mb_substr($value, 0, 160),
            ];
        }

        $this->customAttributes = $cleanAttributes;
        $this->touch();

        return $this;
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

    public function getSoldAt(): ?\DateTimeImmutable
    {
        return $this->soldAt;
    }

    public function isSold(): bool
    {
        return $this->soldAt instanceof \DateTimeImmutable;
    }

    public function markSold(): self
    {
        if (!$this->isSold()) {
            $this->soldAt = new \DateTimeImmutable();
        }

        $this->isActive = false;
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

    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
