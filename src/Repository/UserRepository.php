<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
final class UserRepository extends ServiceEntityRepository
{
    private const ADMIN_ROLE_MARKER = 'ROLE_ADMIN';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email))]);
    }

    public function findOneByPhone(string $phone): ?User
    {
        $normalizedPhone = User::normalizePhone($phone);

        if ($normalizedPhone === '') {
            return null;
        }

        return $this->findOneBy(['phone' => $normalizedPhone]);
    }

    /**
     * @return array{
     *     items: list<array{
     *         customer: User,
     *         appointment_count: int,
     *         last_appointment_at: \DateTimeImmutable|null,
     *         available_balance_cents: int,
     *         pending_balance_cents: int
     *     }>,
     *     total: int,
     *     page: int,
     *     pages: int
     * }
     */
    public function findCustomersForAdmin(string $search = '', string $filter = '', int $page = 1, int $limit = 12): array
    {
        $qb = $this->createQueryBuilder('customer')
            ->select('customer')
            ->addSelect('COUNT(DISTINCT appointment.id) AS appointment_count')
            ->addSelect('MAX(appointment.scheduledAt) AS last_appointment_at')
            ->addSelect('COALESCE(loyaltyAccount.availableBalanceCents, 0) AS available_balance_cents')
            ->addSelect('COALESCE(SUM(CASE WHEN loyaltyTransaction.status = :pending THEN loyaltyTransaction.amountCents ELSE 0 END), 0) AS pending_balance_cents')
            ->leftJoin('customer.appointments', 'appointment')
            ->leftJoin('customer.loyaltyAccount', 'loyaltyAccount')
            ->leftJoin('loyaltyAccount.transactions', 'loyaltyTransaction')
            ->setParameter('pending', 'pending')
            ->groupBy('customer.id')
            ->addGroupBy('loyaltyAccount.id')
            ->addOrderBy('customer.createdAt', 'DESC')
            ->addOrderBy('customer.id', 'DESC');

        $search = mb_strtolower(trim($search));

        if ($search !== '') {
            $qb
                ->andWhere('LOWER(customer.email) LIKE :search OR LOWER(customer.firstName) LIKE :search OR LOWER(customer.lastName) LIKE :search OR customer.phone LIKE :search')
                ->setParameter('search', '%'.$search.'%');
        }

        $rows = array_map(static function (array $row): array {
            if (($row[0] ?? null) instanceof User) {
                $row['customer'] = $row[0];
                unset($row[0]);
            }

            return $row;
        }, $qb->getQuery()->getResult());

        $rows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['customer'] instanceof User
                && !in_array(self::ADMIN_ROLE_MARKER, $row['customer']->getRoles(), true),
        ));

        $rows = array_values(array_filter($rows, static function (array $row) use ($filter): bool {
            /** @var User $customer */
            $customer = $row['customer'];
            $availableBalance = (int) $row['available_balance_cents'];
            $pendingBalance = (int) $row['pending_balance_cents'];

            return match ($filter) {
                'active' => $customer->isActive(),
                'inactive' => !$customer->isActive(),
                'with_balance' => $availableBalance > 0,
                'with_pending' => $pendingBalance > 0,
                default => true,
            };
        }));

        $total = count($rows);
        $page = max(1, $page);

        if ($limit <= 0) {
            return [
                'items' => $rows,
                'total' => $total,
                'page' => 1,
                'pages' => 1,
            ];
        }

        $limit = max(1, $limit);
        $pages = max(1, (int) ceil($total / $limit));
        $page = min($page, $pages);

        return [
            'items' => array_slice($rows, ($page - 1) * $limit, $limit),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ];
    }

    public function countCustomers(): int
    {
        return count(array_filter(
            $this->findAll(),
            static fn (User $user): bool => !in_array(self::ADMIN_ROLE_MARKER, $user->getRoles(), true),
        ));
    }
}
