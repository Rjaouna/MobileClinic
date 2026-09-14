<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RecruitmentApplicationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: RecruitmentApplicationRepository::class)]
#[ORM\Table(name: 'recruitment_application')]
#[ORM\Index(name: 'IDX_RECRUITMENT_STATUS_CREATED', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'IDX_RECRUITMENT_EMAIL', columns: ['email'])]
#[ORM\Index(name: 'IDX_RECRUITMENT_APPLICATION_POSITION', columns: ['position_id'])]
class RecruitmentApplication
{
    public const STATUS_NEW = 'new';
    public const STATUS_REVIEWED = 'reviewed';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_INTERVIEW = 'interview';
    public const STATUS_HIRED = 'hired';
    public const STATUS_REJECTED = 'rejected';

    public const STATUS_LABELS = [
        self::STATUS_NEW => 'Nouvelle candidature',
        self::STATUS_REVIEWED => 'À étudier',
        self::STATUS_CONTACTED => 'Contacté',
        self::STATUS_INTERVIEW => 'Entretien prévu',
        self::STATUS_HIRED => 'Retenu',
        self::STATUS_REJECTED => 'Non retenu',
    ];

    public const STATUS_VARIANTS = [
        self::STATUS_NEW => 'danger',
        self::STATUS_REVIEWED => 'warning',
        self::STATUS_CONTACTED => 'neutral',
        self::STATUS_INTERVIEW => 'warning',
        self::STATUS_HIRED => 'success',
        self::STATUS_REJECTED => 'neutral',
    ];

    public const SPONTANEOUS_POSITION = 'Candidature spontanée';

    public const AVAILABILITY_CHOICES = [
        'Immédiate',
        'Sous 2 semaines',
        'Sous 1 mois',
        'À convenir',
    ];

    public const EXPERIENCE_CHOICES = [
        'Débutant motivé',
        '1 à 2 ans',
        '3 à 5 ans',
        'Plus de 5 ans',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private string $firstName = '';

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private string $lastName = '';

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private string $email = '';

    #[ORM\Column(length: 40)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 40)]
    private string $phone = '';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $desiredPosition = '';

    #[ORM\ManyToOne(targetEntity: RecruitmentPosition::class, inversedBy: 'applications')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?RecruitmentPosition $position = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private string $availability = '';

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private string $experienceLevel = '';

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 20)]
    private string $message = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $resumePath = null;

    #[ORM\Column(length: 40)]
    private string $status = self::STATUS_NEW;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adminNote = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $statusChangedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): self
    {
        $this->firstName = trim($firstName);
        $this->touch();

        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): self
    {
        $this->lastName = trim($lastName);
        $this->touch();

        return $this;
    }

    public function getDisplayName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = mb_strtolower(trim($email));
        $this->touch();

        return $this;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function setPhone(string $phone): self
    {
        $this->phone = trim($phone);
        $this->touch();

        return $this;
    }

    public function getDesiredPosition(): string
    {
        return $this->desiredPosition;
    }

    public function setDesiredPosition(string $desiredPosition): self
    {
        $this->desiredPosition = trim($desiredPosition);
        $this->touch();

        return $this;
    }

    public function getPosition(): ?RecruitmentPosition
    {
        return $this->position;
    }

    public function setPosition(?RecruitmentPosition $position): self
    {
        $this->position = $position;
        $this->touch();

        return $this;
    }

    public function getAvailability(): string
    {
        return $this->availability;
    }

    public function setAvailability(string $availability): self
    {
        $this->availability = trim($availability);
        $this->touch();

        return $this;
    }

    public function getExperienceLevel(): string
    {
        return $this->experienceLevel;
    }

    public function setExperienceLevel(string $experienceLevel): self
    {
        $this->experienceLevel = trim($experienceLevel);
        $this->touch();

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): self
    {
        $this->message = trim($message);
        $this->touch();

        return $this;
    }

    public function getResumePath(): ?string
    {
        return $this->resumePath;
    }

    public function setResumePath(?string $resumePath): self
    {
        $resumePath = $resumePath !== null ? trim($resumePath) : null;
        $this->resumePath = $resumePath !== '' ? $resumePath : null;
        $this->touch();

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        if (!isset(self::STATUS_LABELS[$status])) {
            $status = self::STATUS_NEW;
        }

        if ($this->status !== $status) {
            $this->statusChangedAt = new \DateTimeImmutable();
        }

        $this->status = $status;
        $this->touch();

        return $this;
    }

    public function getStatusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? self::STATUS_LABELS[self::STATUS_NEW];
    }

    public function getStatusVariant(): string
    {
        return self::STATUS_VARIANTS[$this->status] ?? 'neutral';
    }

    public function getAdminNote(): ?string
    {
        return $this->adminNote;
    }

    public function setAdminNote(?string $adminNote): self
    {
        $adminNote = $adminNote !== null ? trim($adminNote) : null;
        $this->adminNote = $adminNote !== '' ? $adminNote : null;
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

    public function getStatusChangedAt(): ?\DateTimeImmutable
    {
        return $this->statusChangedAt;
    }

    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
