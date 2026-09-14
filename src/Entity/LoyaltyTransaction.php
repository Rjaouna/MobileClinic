<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LoyaltyTransactionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: LoyaltyTransactionRepository::class)]
#[ORM\Table(name: 'loyalty_transaction')]
#[ORM\UniqueConstraint(name: 'UNIQ_LOYALTY_TRANSACTION_APPOINTMENT', fields: ['appointment'])]
#[ORM\Index(name: 'IDX_LOYALTY_TRANSACTION_ACCOUNT', columns: ['account_id'])]
#[ORM\Index(name: 'IDX_LOYALTY_TRANSACTION_ADMINISTRATOR', columns: ['administrator_id'])]
#[ORM\Index(name: 'IDX_LOYALTY_TRANSACTION_STATUS', columns: ['status'])]
#[ORM\Index(name: 'IDX_LOYALTY_TRANSACTION_TYPE', columns: ['type'])]
#[ORM\Index(name: 'IDX_LOYALTY_TRANSACTION_PRODUCT_RESERVATION', columns: ['product_reservation_id'])]
class LoyaltyTransaction
{
    public const TYPE_GAIN = 'gain';
    public const TYPE_REDEEM = 'utilisation';
    public const TYPE_ADJUSTMENT = 'ajustement';

    public const STATUS_PENDING = 'pending';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_CANCELLED = 'cancelled';

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        self::TYPE_GAIN => 'Gain',
        self::TYPE_REDEEM => 'Utilisation',
        self::TYPE_ADJUSTMENT => 'Ajustement',
    ];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'En attente',
        self::STATUS_VALIDATED => 'Validé',
        self::STATUS_CANCELLED => 'Annulé',
    ];

    /** @var array<string, string> */
    public const STATUS_VARIANTS = [
        self::STATUS_PENDING => 'warning',
        self::STATUS_VALIDATED => 'success',
        self::STATUS_CANCELLED => 'neutral',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'transactions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?LoyaltyAccount $account = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Appointment $appointment = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ProductReservation $productReservation = null;

    #[ORM\Column]
    #[Assert\NotEqualTo(0)]
    private int $amountCents = 0;

    #[ORM\Column(length: 40)]
    #[Assert\Choice(choices: [self::TYPE_GAIN, self::TYPE_REDEEM, self::TYPE_ADJUSTMENT])]
    private string $type = self::TYPE_GAIN;

    #[ORM\Column(length: 40)]
    #[Assert\Choice(choices: [self::STATUS_PENDING, self::STATUS_VALIDATED, self::STATUS_CANCELLED])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $reason = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $administrator = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $validatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccount(): ?LoyaltyAccount
    {
        return $this->account;
    }

    public function setAccount(LoyaltyAccount $account): self
    {
        $this->account = $account;

        return $this;
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

    public function getProductReservation(): ?ProductReservation
    {
        return $this->productReservation;
    }

    public function setProductReservation(?ProductReservation $productReservation): self
    {
        $this->productReservation = $productReservation;

        return $this;
    }

    public function getAmountCents(): int
    {
        return $this->amountCents;
    }

    public function setAmountCents(int $amountCents): self
    {
        $this->amountCents = $amountCents;

        return $this;
    }

    public function getAbsoluteAmountCents(): int
    {
        return abs($this->amountCents);
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        if (!isset(self::TYPE_LABELS[$type])) {
            throw new \InvalidArgumentException(sprintf('Type de mouvement fidélité inconnu : %s', $type));
        }

        $this->type = $type;

        return $this;
    }

    public function getTypeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        if (!isset(self::STATUS_LABELS[$status])) {
            throw new \InvalidArgumentException(sprintf('Statut de mouvement fidélité inconnu : %s', $status));
        }

        $this->status = $status;

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

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isValidated(): bool
    {
        return $this->status === self::STATUS_VALIDATED;
    }

    public function markValidated(): self
    {
        $this->status = self::STATUS_VALIDATED;
        $this->validatedAt = new \DateTimeImmutable();
        $this->cancelledAt = null;

        return $this;
    }

    public function markPending(): self
    {
        $this->status = self::STATUS_PENDING;
        $this->validatedAt = null;
        $this->cancelledAt = null;

        return $this;
    }

    public function markCancelled(): self
    {
        $this->status = self::STATUS_CANCELLED;
        $this->validatedAt = null;
        $this->cancelledAt = new \DateTimeImmutable();

        return $this;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function setReason(string $reason): self
    {
        $this->reason = trim($reason);

        return $this;
    }

    public function getAdministrator(): ?User
    {
        return $this->administrator;
    }

    public function setAdministrator(?User $administrator): self
    {
        $this->administrator = $administrator;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getValidatedAt(): ?\DateTimeImmutable
    {
        return $this->validatedAt;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }
}
