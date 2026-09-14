<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RecruitmentPositionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: RecruitmentPositionRepository::class)]
#[ORM\Table(name: 'recruitment_position')]
#[ORM\UniqueConstraint(name: 'UNIQ_RECRUITMENT_POSITION_TITLE', fields: ['title'])]
#[ORM\Index(name: 'IDX_RECRUITMENT_POSITION_ACTIVE_CREATED', columns: ['is_active', 'created_at'])]
class RecruitmentPosition
{
    public const CATEGORY_ATELIER = 'Atelier';
    public const CATEGORY_BOUTIQUE = 'Boutique';
    public const CATEGORY_PILOTAGE = 'Pilotage';
    public const CATEGORY_ADMINISTRATIF = 'Administratif';

    public const CATEGORY_CHOICES = [
        self::CATEGORY_ATELIER,
        self::CATEGORY_BOUTIQUE,
        self::CATEGORY_PILOTAGE,
        self::CATEGORY_ADMINISTRATIF,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 140, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 140)]
    private string $title = '';

    #[ORM\Column(length: 40)]
    #[Assert\Choice(choices: self::CATEGORY_CHOICES)]
    private string $category = self::CATEGORY_ATELIER;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 20)]
    private string $description = '';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $rhythm = '';

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\Count(min: 1, max: 8, minMessage: 'Ajoutez au moins une compétence.', maxMessage: 'Ajoutez au maximum 8 compétences.')]
    private array $highlights = [];

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, RecruitmentApplication> */
    #[ORM\OneToMany(mappedBy: 'position', targetEntity: RecruitmentApplication::class)]
    private Collection $applications;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->applications = new ArrayCollection();
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

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): self
    {
        $this->category = in_array($category, self::CATEGORY_CHOICES, true) ? $category : self::CATEGORY_ATELIER;
        $this->touch();

        return $this;
    }

    public function getIconName(): string
    {
        return match ($this->category) {
            self::CATEGORY_ATELIER => 'tool',
            self::CATEGORY_BOUTIQUE => 'user',
            self::CATEGORY_PILOTAGE => 'briefcase',
            default => 'spark',
        };
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

    public function getRhythm(): string
    {
        return $this->rhythm;
    }

    public function setRhythm(string $rhythm): self
    {
        $this->rhythm = trim($rhythm);
        $this->touch();

        return $this;
    }

    /** @return list<string> */
    public function getHighlights(): array
    {
        return $this->highlights;
    }

    /** @param list<string|null> $highlights */
    public function setHighlights(array $highlights): self
    {
        $cleanHighlights = [];
        $seen = [];

        foreach ($highlights as $highlight) {
            $value = mb_substr(trim((string) $highlight), 0, 100);
            $key = mb_strtolower($value);

            if ($value === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $cleanHighlights[] = $value;

            if (count($cleanHighlights) === 8) {
                break;
            }
        }

        $this->highlights = $cleanHighlights;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, RecruitmentApplication> */
    public function getApplications(): Collection
    {
        return $this->applications;
    }

    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
