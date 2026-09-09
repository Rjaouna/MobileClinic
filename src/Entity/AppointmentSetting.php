<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AppointmentSettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AppointmentSettingRepository::class)]
#[ORM\Table(name: 'appointment_setting')]
class AppointmentSetting
{
    public const DEFAULT_ID = 1;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $noShowDelayMinutes = 60;

    #[ORM\Column]
    private int $bookingWindowDays = 21;

    #[ORM\Column]
    private int $defaultSlotDurationMinutes = 30;

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

    public function getNoShowDelayMinutes(): int
    {
        return $this->noShowDelayMinutes;
    }

    public function setNoShowDelayMinutes(int $noShowDelayMinutes): self
    {
        $this->noShowDelayMinutes = max(1, $noShowDelayMinutes);
        $this->touch();

        return $this;
    }

    public function getBookingWindowDays(): int
    {
        return $this->bookingWindowDays;
    }

    public function setBookingWindowDays(int $bookingWindowDays): self
    {
        $this->bookingWindowDays = max(1, $bookingWindowDays);
        $this->touch();

        return $this;
    }

    public function getDefaultSlotDurationMinutes(): int
    {
        return $this->defaultSlotDurationMinutes;
    }

    public function setDefaultSlotDurationMinutes(int $defaultSlotDurationMinutes): self
    {
        $this->defaultSlotDurationMinutes = max(1, $defaultSlotDurationMinutes);
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
