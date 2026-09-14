<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LoyaltyAccountRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LoyaltyAccountRepository::class)]
#[ORM\Table(name: 'loyalty_account')]
#[ORM\UniqueConstraint(name: 'UNIQ_LOYALTY_ACCOUNT_CUSTOMER', fields: ['customer'])]
class LoyaltyAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'loyaltyAccount')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?User $customer = null;

    #[ORM\Column]
    private int $availableBalanceCents = 0;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, LoyaltyTransaction> */
    #[ORM\OneToMany(mappedBy: 'account', targetEntity: LoyaltyTransaction::class, orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'DESC', 'id' => 'DESC'])]
    private Collection $transactions;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->transactions = new ArrayCollection();
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

        if ($customer->getLoyaltyAccount() !== $this) {
            $customer->setLoyaltyAccount($this);
        }

        return $this;
    }

    public function getAvailableBalanceCents(): int
    {
        return $this->availableBalanceCents;
    }

    public function setAvailableBalanceCents(int $availableBalanceCents): self
    {
        $this->availableBalanceCents = max(0, $availableBalanceCents);
        $this->touch();

        return $this;
    }

    public function addToAvailableBalance(int $amountCents): self
    {
        return $this->setAvailableBalanceCents($this->availableBalanceCents + $amountCents);
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

    /** @return Collection<int, LoyaltyTransaction> */
    public function getTransactions(): Collection
    {
        return $this->transactions;
    }

    public function addTransaction(LoyaltyTransaction $transaction): self
    {
        if (!$this->transactions->contains($transaction)) {
            $this->transactions->add($transaction);
            $transaction->setAccount($this);
        }

        return $this;
    }

    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
