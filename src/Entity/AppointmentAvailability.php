<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AppointmentAvailabilityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AppointmentAvailabilityRepository::class)]
#[ORM\Table(name: 'appointment_availability')]
#[ORM\Index(name: 'IDX_APPOINTMENT_AVAILABILITY_DAY', columns: ['day_of_week', 'is_enabled'])]
class AppointmentAvailability
{
    public const DAYS = [
        1 => 'Lundi',
        2 => 'Mardi',
        3 => 'Mercredi',
        4 => 'Jeudi',
        5 => 'Vendredi',
        6 => 'Samedi',
        7 => 'Dimanche',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $dayOfWeek = 1;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    private ?\DateTimeImmutable $startTime = null;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    private ?\DateTimeImmutable $endTime = null;

    #[ORM\Column]
    private int $slotDurationMinutes = 30;

    #[ORM\Column]
    private bool $isEnabled = true;

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

    public function getDayOfWeek(): int
    {
        return $this->dayOfWeek;
    }

    public function setDayOfWeek(int $dayOfWeek): self
    {
        if (!isset(self::DAYS[$dayOfWeek])) {
            throw new \InvalidArgumentException('Le jour sélectionné est invalide.');
        }

        $this->dayOfWeek = $dayOfWeek;

        return $this;
    }

    public function getDayLabel(): string
    {
        return self::DAYS[$this->dayOfWeek] ?? 'Jour inconnu';
    }

    public function getStartTime(): ?\DateTimeImmutable
    {
        return $this->startTime;
    }

    public function setStartTime(\DateTimeImmutable $startTime): self
    {
        $this->startTime = $startTime;

        return $this;
    }

    public function getEndTime(): ?\DateTimeImmutable
    {
        return $this->endTime;
    }

    public function setEndTime(\DateTimeImmutable $endTime): self
    {
        $this->endTime = $endTime;

        return $this;
    }

    public function getSlotDurationMinutes(): int
    {
        return $this->slotDurationMinutes;
    }

    public function setSlotDurationMinutes(int $slotDurationMinutes): self
    {
        $this->slotDurationMinutes = max(1, $slotDurationMinutes);

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    public function setIsEnabled(bool $isEnabled): self
    {
        $this->isEnabled = $isEnabled;
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
