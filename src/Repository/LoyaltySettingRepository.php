<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LoyaltySetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LoyaltySetting>
 */
final class LoyaltySettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LoyaltySetting::class);
    }

    public function findCurrent(): ?LoyaltySetting
    {
        return $this->find(LoyaltySetting::DEFAULT_ID);
    }
}
