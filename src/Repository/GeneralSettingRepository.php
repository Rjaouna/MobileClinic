<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GeneralSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GeneralSetting>
 */
final class GeneralSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GeneralSetting::class);
    }

    public function findCurrent(): ?GeneralSetting
    {
        return $this->find(GeneralSetting::DEFAULT_ID) ?? $this->findOneBy([], ['id' => 'ASC']);
    }
}
