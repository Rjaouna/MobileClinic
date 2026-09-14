<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LoyaltySettingRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: LoyaltySettingRepository::class)]
#[ORM\Table(name: 'loyalty_setting')]
class LoyaltySetting
{
    public const DEFAULT_ID = 1;
    public const DEFAULT_APPOINTMENT_REWARD_CENTS = 200;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $appointmentRewardCents = self::DEFAULT_APPOINTMENT_REWARD_CENTS;

    #[ORM\Column]
    private bool $isEnabled = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAppointmentRewardCents(): int
    {
        return $this->appointmentRewardCents;
    }

    public function setAppointmentRewardCents(int $appointmentRewardCents): self
    {
        $this->appointmentRewardCents = max(0, $appointmentRewardCents);
        $this->touch();

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
