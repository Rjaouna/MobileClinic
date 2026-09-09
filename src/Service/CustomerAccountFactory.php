<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CustomerAccountFactory
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * @return array{user: User, temporary_password: string|null, created: bool}
     */
    public function findOrCreateCustomer(string $email, string $phone): array
    {
        $email = mb_strtolower(trim($email));
        $user = $this->userRepository->findOneByEmail($email);

        if ($user instanceof User) {
            return [
                'user' => $user,
                'temporary_password' => null,
                'created' => false,
            ];
        }

        $temporaryPassword = $this->generateTemporaryPassword();
        $user = (new User())
            ->setEmail($email)
            ->setPhone($phone)
            ->setRoles(['ROLE_USER'])
            ->requirePasswordChange();
        $user->setPassword($this->passwordHasher->hashPassword($user, $temporaryPassword));

        $this->entityManager->persist($user);

        return [
            'user' => $user,
            'temporary_password' => $temporaryPassword,
            'created' => true,
        ];
    }

    private function generateTemporaryPassword(): string
    {
        return sprintf('SC-%s-%s!', strtoupper(bin2hex(random_bytes(3))), strtoupper(bin2hex(random_bytes(2))));
    }
}
