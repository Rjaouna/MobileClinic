<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AppointmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AppointmentRepository::class)]
#[ORM\Table(name: 'appointment')]
#[ORM\Index(name: 'IDX_APPOINTMENT_STATUS_SCHEDULED_AT', columns: ['status', 'scheduled_at'])]
#[ORM\Index(name: 'IDX_APPOINTMENT_EMAIL', columns: ['email'])]
class Appointment
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_NO_SHOW = 'no_show';
    public const STATUS_CANCELLED_BY_CLIENT = 'cancelled_by_client';
    public const STATUS_CANCELLED_BY_ADMIN = 'cancelled_by_admin';

    /** @var list<string> */
    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
        self::STATUS_IN_PROGRESS,
    ];

    /** @var list<string> */
    public const ADMIN_DECISION_REQUIRED_STATUSES = [
        self::STATUS_PENDING,
    ];

    /** @var list<string> */
    public const ADMIN_ACTION_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_NO_SHOW,
        self::STATUS_CANCELLED_BY_ADMIN,
    ];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'En attente',
        self::STATUS_CONFIRMED => 'Client arrivé',
        self::STATUS_IN_PROGRESS => 'Réparation en cours',
        self::STATUS_COMPLETED => 'Réparation terminée',
        self::STATUS_NO_SHOW => 'Client absent',
        self::STATUS_CANCELLED_BY_CLIENT => 'Annulé par le client',
        self::STATUS_CANCELLED_BY_ADMIN => 'Rendez-vous annulé',
    ];

    /** @var array<string, string> */
    public const STATUS_VARIANTS = [
        self::STATUS_PENDING => 'warning',
        self::STATUS_CONFIRMED => 'success',
        self::STATUS_IN_PROGRESS => 'warning',
        self::STATUS_COMPLETED => 'success',
        self::STATUS_NO_SHOW => 'danger',
        self::STATUS_CANCELLED_BY_CLIENT => 'neutral',
        self::STATUS_CANCELLED_BY_ADMIN => 'neutral',
    ];

    /** @var array<string, string> */
    public const STATUS_CONSEQUENCES = [
        self::STATUS_PENDING => 'Le rendez-vous reste à traiter et les 2 € fidélité restent en attente.',
        self::STATUS_CONFIRMED => 'Le client est indiqué comme arrivé. Les 2 € fidélité restent en attente jusqu’à la fin de la réparation.',
        self::STATUS_IN_PROGRESS => 'La réparation est en cours. Les 2 € fidélité restent en attente.',
        self::STATUS_COMPLETED => 'Les 2 € en attente seront validés dans la cagnotte du client.',
        self::STATUS_NO_SHOW => 'Les 2 € en attente seront annulés car le client est absent.',
        self::STATUS_CANCELLED_BY_ADMIN => 'Les 2 € en attente seront annulés car le rendez-vous est annulé.',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'appointments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $customer = null;

    #[ORM\Column(length: 180)]
    private string $email = '';

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 80)]
    private string $device = '';

    #[ORM\Column(length: 80)]
    private string $problem = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $scheduledAt = null;

    #[ORM\Column]
    private int $durationMinutes = 30;

    #[ORM\Column(length: 40)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $customerNote = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adminNote = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $statusChangedAt = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $statusChangedByEmail = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $rescheduledAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): ?User
    {
        return $this->customer;
    }

    public function setCustomer(?User $customer): self
    {
        $this->customer = $customer;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $phone = $phone !== null ? User::normalizePhone($phone) : null;
        $this->phone = $phone !== '' ? $phone : null;

        return $this;
    }

    public function getDevice(): string
    {
        return $this->device;
    }

    public function setDevice(string $device): self
    {
        $this->device = trim($device);

        return $this;
    }

    public function getProblem(): string
    {
        return $this->problem;
    }

    public function setProblem(string $problem): self
    {
        $this->problem = trim($problem);

        return $this;
    }

    public function getScheduledAt(): ?\DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(\DateTimeImmutable $scheduledAt): self
    {
        $this->scheduledAt = $scheduledAt;
        $this->touch();

        return $this;
    }

    public function getDurationMinutes(): int
    {
        return $this->durationMinutes;
    }

    public function setDurationMinutes(int $durationMinutes): self
    {
        $this->durationMinutes = max(1, $durationMinutes);

        return $this;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->scheduledAt?->modify(sprintf('+%d minutes', $this->durationMinutes));
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        if (!isset(self::STATUS_LABELS[$status])) {
            throw new \InvalidArgumentException(sprintf('Statut de rendez-vous inconnu : %s', $status));
        }

        if ($this->status !== $status) {
            $this->status = $status;
            $this->statusChangedAt = new \DateTimeImmutable();

            if (str_starts_with($status, 'cancelled')) {
                $this->cancelledAt = new \DateTimeImmutable();
            }

            $this->touch();
        }

        return $this;
    }

    public function getStatusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getStatusVariant(): string
    {
        return self::STATUS_VARIANTS[$this->status] ?? 'neutral';
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function needsAdminDecision(): bool
    {
        return in_array($this->status, self::ADMIN_DECISION_REQUIRED_STATUSES, true);
    }

    public function canBeChangedByCustomer(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        return $this->isActive() && $this->scheduledAt instanceof \DateTimeImmutable && $this->scheduledAt > $now;
    }

    public function getCustomerNote(): ?string
    {
        return $this->customerNote;
    }

    public function setCustomerNote(?string $customerNote): self
    {
        $customerNote = $customerNote !== null ? trim($customerNote) : null;
        $this->customerNote = $customerNote !== '' ? $customerNote : null;

        return $this;
    }

    public function getAdminNote(): ?string
    {
        return $this->adminNote;
    }

    public function setAdminNote(?string $adminNote): self
    {
        $adminNote = $adminNote !== null ? trim($adminNote) : null;
        $this->adminNote = $adminNote !== '' ? $adminNote : null;

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

    public function getStatusChangedByEmail(): ?string
    {
        return $this->statusChangedByEmail;
    }

    public function setStatusChangedBy(?User $administrator): self
    {
        $this->statusChangedByEmail = $administrator?->getEmail();
        $this->touch();

        return $this;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function getRescheduledAt(): ?\DateTimeImmutable
    {
        return $this->rescheduledAt;
    }

    public function markRescheduled(): self
    {
        $this->rescheduledAt = new \DateTimeImmutable();
        $this->status = self::STATUS_PENDING;
        $this->statusChangedAt = new \DateTimeImmutable();
        $this->cancelledAt = null;
        $this->touch();

        return $this;
    }

    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
