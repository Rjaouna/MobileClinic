<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductReservationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductReservationRepository::class)]
#[ORM\Table(name: 'product_reservation')]
#[ORM\Index(name: 'IDX_PRODUCT_RESERVATION_CUSTOMER', columns: ['customer_id'])]
#[ORM\Index(name: 'IDX_PRODUCT_RESERVATION_STATUS_EXPIRES', columns: ['status', 'expires_at'])]
#[ORM\Index(name: 'IDX_PRODUCT_RESERVATION_CREATED', columns: ['created_at'])]
class ProductReservation
{
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_SOLD = 'sold';
    public const STATUS_CANCELLED_BY_CUSTOMER = 'cancelled_by_customer';
    public const STATUS_CANCELLED_BY_ADMIN = 'cancelled_by_admin';
    public const STATUS_EXPIRED = 'expired';

    /** @var list<string> */
    public const ACTIVE_STATUSES = [
        self::STATUS_RESERVED,
        self::STATUS_CONFIRMED,
    ];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_RESERVED => 'Réservé',
        self::STATUS_CONFIRMED => 'Validé définitivement',
        self::STATUS_SOLD => 'Retiré / vendu',
        self::STATUS_CANCELLED_BY_CUSTOMER => 'Annulé par le client',
        self::STATUS_CANCELLED_BY_ADMIN => 'Annulé par l’admin',
        self::STATUS_EXPIRED => 'Expiré',
    ];

    /** @var array<string, string> */
    public const STATUS_VARIANTS = [
        self::STATUS_RESERVED => 'warning',
        self::STATUS_CONFIRMED => 'success',
        self::STATUS_SOLD => 'success',
        self::STATUS_CANCELLED_BY_CUSTOMER => 'neutral',
        self::STATUS_CANCELLED_BY_ADMIN => 'neutral',
        self::STATUS_EXPIRED => 'danger',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?User $customer = null;

    #[ORM\Column(length: 40)]
    private string $status = self::STATUS_RESERVED;

    #[ORM\Column]
    private int $totalCents = 0;

    #[ORM\Column]
    private int $loyaltyUsedCents = 0;

    #[ORM\Column]
    private int $loyaltyRefundedCents = 0;

    #[ORM\Column]
    private int $payableCents = 0;

    #[ORM\Column(length: 40)]
    private string $storePhone = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $withdrawnAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiredAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adminNote = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, ProductReservationItem> */
    #[ORM\OneToMany(mappedBy: 'reservation', targetEntity: ProductReservationItem::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $items;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): ?User
    {
        return $this->customer;
    }

    public function setCustomer(User $customer): self
    {
        $this->customer = $customer;
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
            throw new \InvalidArgumentException(sprintf('Statut de réservation boutique inconnu : %s', $status));
        }

        if ($this->status !== $status) {
            $this->status = $status;
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

    public function isReserved(): bool
    {
        return $this->status === self::STATUS_RESERVED;
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function isSold(): bool
    {
        return $this->status === self::STATUS_SOLD;
    }

    public function isBlockingProduct(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function canBeCancelledByCustomer(): bool
    {
        return $this->isReserved();
    }

    public function getTotalCents(): int
    {
        return $this->totalCents;
    }

    public function setTotalCents(int $totalCents): self
    {
        $this->totalCents = max(0, $totalCents);
        $this->recalculatePayable();

        return $this;
    }

    public function getLoyaltyUsedCents(): int
    {
        return $this->loyaltyUsedCents;
    }

    public function setLoyaltyUsedCents(int $loyaltyUsedCents): self
    {
        $this->loyaltyUsedCents = max(0, $loyaltyUsedCents);
        $this->recalculatePayable();

        return $this;
    }

    public function getLoyaltyRefundedCents(): int
    {
        return $this->loyaltyRefundedCents;
    }

    public function addLoyaltyRefundedCents(int $amountCents): self
    {
        $this->loyaltyRefundedCents = min($this->loyaltyUsedCents, max(0, $this->loyaltyRefundedCents + $amountCents));
        $this->touch();

        return $this;
    }

    public function getRefundableLoyaltyCents(): int
    {
        if ($this->isSold()) {
            return 0;
        }

        return max(0, $this->loyaltyUsedCents - $this->loyaltyRefundedCents);
    }

    public function getPayableCents(): int
    {
        return $this->payableCents;
    }

    public function getStorePhone(): string
    {
        return $this->storePhone;
    }

    public function setStorePhone(string $storePhone): self
    {
        $this->storePhone = trim($storePhone);
        $this->touch();

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;
        $this->touch();

        return $this;
    }

    public function getConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function confirm(): self
    {
        $this->setStatus(self::STATUS_CONFIRMED);
        $this->confirmedAt = new \DateTimeImmutable();
        $this->expiresAt = null;
        $this->touch();

        return $this;
    }

    public function getWithdrawnAt(): ?\DateTimeImmutable
    {
        return $this->withdrawnAt;
    }

    public function withdraw(): self
    {
        if (!$this->confirmedAt instanceof \DateTimeImmutable) {
            $this->confirmedAt = new \DateTimeImmutable();
        }

        $this->setStatus(self::STATUS_SOLD);
        $this->withdrawnAt = new \DateTimeImmutable();
        $this->expiresAt = null;
        $this->touch();

        return $this;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function cancelByCustomer(): self
    {
        $this->setStatus(self::STATUS_CANCELLED_BY_CUSTOMER);
        $this->cancelledAt = new \DateTimeImmutable();
        $this->touch();

        return $this;
    }

    public function cancelByAdmin(): self
    {
        $this->setStatus(self::STATUS_CANCELLED_BY_ADMIN);
        $this->cancelledAt = new \DateTimeImmutable();
        $this->touch();

        return $this;
    }

    public function expire(): self
    {
        $this->setStatus(self::STATUS_EXPIRED);
        $this->expiredAt = new \DateTimeImmutable();
        $this->touch();

        return $this;
    }

    public function getExpiredAt(): ?\DateTimeImmutable
    {
        return $this->expiredAt;
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

    /** @return Collection<int, ProductReservationItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(ProductReservationItem $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setReservation($this);
        }

        return $this;
    }

    private function recalculatePayable(): void
    {
        $this->payableCents = max(0, $this->totalCents - $this->loyaltyUsedCents);
        $this->touch();
    }

    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
