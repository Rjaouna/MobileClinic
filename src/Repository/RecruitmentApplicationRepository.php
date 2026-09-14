<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RecruitmentApplication;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecruitmentApplication>
 */
final class RecruitmentApplicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecruitmentApplication::class);
    }

    /** @return list<RecruitmentApplication> */
    public function findForAdmin(string $search = '', string $status = ''): array
    {
        $qb = $this->createQueryBuilder('application')
            ->addOrderBy('application.createdAt', 'DESC')
            ->addOrderBy('application.id', 'DESC');

        $search = mb_strtolower(trim($search));

        if ($search !== '') {
            $qb
                ->andWhere(
                    'LOWER(application.firstName) LIKE :search OR LOWER(application.lastName) LIKE :search OR LOWER(application.email) LIKE :search OR application.phone LIKE :search OR LOWER(application.desiredPosition) LIKE :search'
                )
                ->setParameter('search', '%'.$search.'%');
        }

        if (isset(RecruitmentApplication::STATUS_LABELS[$status])) {
            $qb
                ->andWhere('application.status = :status')
                ->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    public function countOpen(): int
    {
        return (int) $this->createQueryBuilder('application')
            ->select('COUNT(application.id)')
            ->andWhere('application.status IN (:statuses)')
            ->setParameter('statuses', [
                RecruitmentApplication::STATUS_NEW,
                RecruitmentApplication::STATUS_REVIEWED,
                RecruitmentApplication::STATUS_CONTACTED,
                RecruitmentApplication::STATUS_INTERVIEW,
            ])
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countAllApplications(): int
    {
        return (int) $this->createQueryBuilder('application')
            ->select('COUNT(application.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
