<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[ORM\UniqueConstraint(name: 'UNIQ_USER_PHONE', fields: ['phone'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $email = '';

    #[ORM\Column(length: 40, nullable: true)]
    #[Assert\Length(max: 40)]
    private ?string $phone = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $firstName = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $lastName = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column(length: 255)]
    private string $password = '';

    #[ORM\Column]
    private bool $mustChangePassword = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $temporaryPasswordIssuedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, Appointment> */
    #[ORM\OneToMany(mappedBy: 'customer', targetEntity: Appointment::class, orphanRemoval: true)]
    private Collection $appointments;

    #[ORM\OneToOne(mappedBy: 'customer', targetEntity: LoyaltyAccount::class, cascade: ['persist'])]
    private ?LoyaltyAccount $loyaltyAccount = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->appointments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $normalizedPhone = $phone !== null ? self::normalizePhone($phone) : '';
        $this->phone = $normalizedPhone !== '' ? $normalizedPhone : null;
        $this->touch();

        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): self
    {
        $firstName = $firstName !== null ? trim($firstName) : null;
        $this->firstName = $firstName !== '' ? $firstName : null;
        $this->touch();

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): self
    {
        $lastName = $lastName !== null ? trim($lastName) : null;
        $this->lastName = $lastName !== '' ? $lastName : null;
        $this->touch();

        return $this;
    }

    public function getDisplayName(): string
    {
        $name = trim(sprintf('%s %s', $this->firstName ?? '', $this->lastName ?? ''));

        return $name !== '' ? $name : $this->email;
    }

    public static function normalizePhone(string $phone): string
    {
        $cleanPhone = preg_replace('/[^\d+]/', '', trim($phone));
        $cleanPhone = is_string($cleanPhone) ? preg_replace('/(?!^)\+/', '', $cleanPhone) : '';
        $cleanPhone = is_string($cleanPhone) ? $cleanPhone : '';

        if (str_starts_with($cleanPhone, '00')) {
            $cleanPhone = '+'.mb_substr($cleanPhone, 2);
        }

        if (preg_match('/^0[1-9]\d{8}$/', $cleanPhone) === 1) {
            return '+33'.mb_substr($cleanPhone, 1);
        }

        return $cleanPhone;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): self
    {
        $this->roles = array_values(array_unique($roles));

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;
        $this->touch();

        return $this;
    }

    public function eraseCredentials(): void
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'phone' => $this->phone,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'roles' => $this->roles,
            'password' => $this->password,
            'mustChangePassword' => $this->mustChangePassword,
            'isActive' => $this->isActive,
            'temporaryPasswordIssuedAt' => $this->temporaryPasswordIssuedAt,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->id = is_int($data['id'] ?? null) ? $data['id'] : null;
        $this->email = is_string($data['email'] ?? null) ? $data['email'] : '';
        $this->phone = is_string($data['phone'] ?? null) ? $data['phone'] : null;
        $this->firstName = is_string($data['firstName'] ?? null) ? $data['firstName'] : null;
        $this->lastName = is_string($data['lastName'] ?? null) ? $data['lastName'] : null;
        $this->roles = is_array($data['roles'] ?? null) ? array_values(array_filter($data['roles'], 'is_string')) : [];
        $this->password = is_string($data['password'] ?? null) ? $data['password'] : '';
        $this->mustChangePassword = (bool) ($data['mustChangePassword'] ?? false);
        $this->isActive = (bool) ($data['isActive'] ?? true);
        $this->temporaryPasswordIssuedAt = ($data['temporaryPasswordIssuedAt'] ?? null) instanceof \DateTimeImmutable ? $data['temporaryPasswordIssuedAt'] : null;
        $this->createdAt = ($data['createdAt'] ?? null) instanceof \DateTimeImmutable ? $data['createdAt'] : new \DateTimeImmutable();
        $this->updatedAt = ($data['updatedAt'] ?? null) instanceof \DateTimeImmutable ? $data['updatedAt'] : null;
        $this->appointments = new ArrayCollection();
        $this->loyaltyAccount = null;
    }

    public function mustChangePassword(): bool
    {
        return $this->mustChangePassword;
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

    public function requirePasswordChange(): self
    {
        $this->mustChangePassword = true;
        $this->temporaryPasswordIssuedAt = new \DateTimeImmutable();
        $this->touch();

        return $this;
    }

    public function markPasswordChanged(): self
    {
        $this->mustChangePassword = false;
        $this->temporaryPasswordIssuedAt = null;
        $this->touch();

        return $this;
    }

    public function getTemporaryPasswordIssuedAt(): ?\DateTimeImmutable
    {
        return $this->temporaryPasswordIssuedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, Appointment> */
    public function getAppointments(): Collection
    {
        return $this->appointments;
    }

    public function getLoyaltyAccount(): ?LoyaltyAccount
    {
        return $this->loyaltyAccount;
    }

    public function setLoyaltyAccount(?LoyaltyAccount $loyaltyAccount): self
    {
        if ($loyaltyAccount?->getCustomer() !== $this) {
            $loyaltyAccount?->setCustomer($this);
        }

        $this->loyaltyAccount = $loyaltyAccount;

        return $this;
    }

    public function addAppointment(Appointment $appointment): self
    {
        if (!$this->appointments->contains($appointment)) {
            $this->appointments->add($appointment);
            $appointment->setCustomer($this);
        }

        return $this;
    }

    public function removeAppointment(Appointment $appointment): self
    {
        if ($this->appointments->removeElement($appointment) && $appointment->getCustomer() === $this) {
            $appointment->setCustomer(null);
        }

        return $this;
    }

    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
