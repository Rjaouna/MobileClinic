<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AppointmentNotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AppointmentNotificationRepository::class)]
#[ORM\Table(name: 'appointment_notification')]
#[ORM\UniqueConstraint(name: 'UNIQ_APPOINTMENT_NOTIFICATION_KEY', fields: ['uniqueKey'])]
#[ORM\UniqueConstraint(name: 'UNIQ_APPOINTMENT_NOTIFICATION_TYPE_APPOINTMENT', fields: ['type', 'appointment'])]
#[ORM\Index(name: 'IDX_APPOINTMENT_NOTIFICATION_APPOINTMENT', columns: ['appointment_id'])]
#[ORM\Index(name: 'IDX_APPOINTMENT_NOTIFICATION_STATUS', columns: ['status'])]
#[ORM\Index(name: 'IDX_APPOINTMENT_NOTIFICATION_TYPE_STATUS', columns: ['type', 'status'])]
class AppointmentNotification
{
    public const TYPE_APPOINTMENT_DUE = 'APPOINTMENT_DUE';
    public const TYPE_APPOINTMENT_OVERDUE = 'APPOINTMENT_OVERDUE';

    public const LEVEL_INFORMATION = 'information';
    public const LEVEL_ATTENTION = 'attention';
    public const LEVEL_URGENT = 'urgent';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_RESOLVED = 'resolved';

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        self::TYPE_APPOINTMENT_DUE => 'Rendez-vous prévu maintenant',
        self::TYPE_APPOINTMENT_OVERDUE => 'Rendez-vous à régulariser',
    ];

    /** @var array<string, string> */
    public const LEVEL_VARIANTS = [
        self::LEVEL_INFORMATION => 'information',
        self::LEVEL_ATTENTION => 'attention',
        self::LEVEL_URGENT => 'urgent',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $uniqueKey = '';

    #[ORM\Column(length: 60)]
    private string $type = self::TYPE_APPOINTMENT_DUE;

    #[ORM\Column(length: 30)]
    private string $level = self::LEVEL_ATTENTION;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Appointment $appointment = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $recipientEmail = null;

    #[ORM\Column(length: 140)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $message = '';

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUniqueKey(): string
    {
        return $this->uniqueKey;
    }

    public function setUniqueKey(string $uniqueKey): self
    {
        $this->uniqueKey = trim($uniqueKey);

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        if (!isset(self::TYPE_LABELS[$type])) {
            throw new \InvalidArgumentException(sprintf('Type de notification inconnu : %s', $type));
        }

        $this->type = $type;
        $this->touch();

        return $this;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function setLevel(string $level): self
    {
        if (!isset(self::LEVEL_VARIANTS[$level])) {
            throw new \InvalidArgumentException(sprintf('Niveau de notification inconnu : %s', $level));
        }

        $this->level = $level;
        $this->touch();

        return $this;
    }

    public function getLevelVariant(): string
    {
        return self::LEVEL_VARIANTS[$this->level] ?? self::LEVEL_ATTENTION;
    }

    public function getAppointment(): ?Appointment
    {
        return $this->appointment;
    }

    public function setAppointment(?Appointment $appointment): self
    {
        $this->appointment = $appointment;

        return $this;
    }

    public function getRecipientEmail(): ?string
    {
        return $this->recipientEmail;
    }

    public function setRecipientEmail(?string $recipientEmail): self
    {
        $recipientEmail = $recipientEmail !== null ? mb_strtolower(trim($recipientEmail)) : null;
        $this->recipientEmail = $recipientEmail !== '' ? $recipientEmail : null;

        return $this;
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function markRead(?\DateTimeImmutable $readAt = null): self
    {
        $this->readAt ??= $readAt ?? new \DateTimeImmutable();
        $this->touch();

        return $this;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function resolve(?\DateTimeImmutable $resolvedAt = null): self
    {
        if ($this->status === self::STATUS_RESOLVED) {
            return $this;
        }

        $this->status = self::STATUS_RESOLVED;
        $this->resolvedAt = $resolvedAt ?? new \DateTimeImmutable();
        $this->touch();

        return $this;
    }

    public function reactivate(): self
    {
        $this->status = self::STATUS_ACTIVE;
        $this->resolvedAt = null;
        $this->touch();

        return $this;
    }

    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
