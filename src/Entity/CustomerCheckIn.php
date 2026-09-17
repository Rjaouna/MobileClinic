<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CustomerCheckInRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CustomerCheckInRepository::class)]
#[ORM\Table(name: 'customer_check_in')]
#[ORM\Index(name: 'IDX_CUSTOMER_CHECK_IN_CUSTOMER', columns: ['customer_id'])]
#[ORM\Index(name: 'IDX_CUSTOMER_CHECK_IN_RESOLVED', columns: ['resolved_at'])]
#[ORM\Index(name: 'IDX_CUSTOMER_CHECK_IN_LAST_SCAN', columns: ['last_scanned_at'])]
class CustomerCheckIn
{
    public const RESOLUTION_LOYALTY_OPENED = 'loyalty_opened';
    public const RESOLUTION_DISMISSED = 'dismissed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $customer = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $resolvedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastScannedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column]
    private int $scanCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $resolution = null;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->lastScannedAt = $now;
        $this->expiresAt = $now;
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

        return $this;
    }

    public function registerScan(\DateTimeImmutable $scannedAt, \DateTimeImmutable $expiresAt): self
    {
        if ($expiresAt <= $scannedAt) {
            throw new \InvalidArgumentException('La date d’expiration doit être postérieure au scan.');
        }

        if ($this->scanCount === 0) {
            $this->createdAt = $scannedAt;
        }

        $this->lastScannedAt = $scannedAt;
        $this->expiresAt = $expiresAt;
        ++$this->scanCount;

        return $this;
    }

    public function resolve(User $administrator, string $resolution, ?\DateTimeImmutable $resolvedAt = null): self
    {
        if (!in_array($resolution, [self::RESOLUTION_LOYALTY_OPENED, self::RESOLUTION_DISMISSED], true)) {
            throw new \InvalidArgumentException('Résolution de présence inconnue.');
        }

        if ($this->resolvedAt !== null) {
            return $this;
        }

        $this->resolvedBy = $administrator;
        $this->resolution = $resolution;
        $this->resolvedAt = $resolvedAt ?? new \DateTimeImmutable();

        return $this;
    }

    public function isResolved(): bool
    {
        return $this->resolvedAt !== null;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastScannedAt(): \DateTimeImmutable
    {
        return $this->lastScannedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getScanCount(): int
    {
        return $this->scanCount;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getResolvedBy(): ?User
    {
        return $this->resolvedBy;
    }

    public function getResolution(): ?string
    {
        return $this->resolution;
    }
}
