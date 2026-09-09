<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AppointmentSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AppointmentSetting>
 */
final class AppointmentSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppointmentSetting::class);
    }

    public function findCurrent(): ?AppointmentSetting
    {
        return $this->find(AppointmentSetting::DEFAULT_ID);
    }
}
