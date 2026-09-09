<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
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
    private string $email = '';

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column(length: 255)]
    private string $password = '';

    #[ORM\Column]
    private bool $mustChangePassword = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $temporaryPasswordIssuedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, Appointment> */
    #[ORM\OneToMany(mappedBy: 'customer', targetEntity: Appointment::class, orphanRemoval: true)]
    private Collection $appointments;

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

    public function mustChangePassword(): bool
    {
        return $this->mustChangePassword;
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
