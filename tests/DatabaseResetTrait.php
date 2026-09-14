<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

trait DatabaseResetTrait
{
    protected function resetDoctrineSchema(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $metadata = array_values(array_filter(
            $entityManager->getMetadataFactory()->getAllMetadata(),
            static fn ($classMetadata): bool => !$classMetadata->isMappedSuperclass,
        ));

        if ($metadata !== []) {
            $schemaTool = new SchemaTool($entityManager);
            $schemaTool->dropSchema($metadata);
            $schemaTool->createSchema($metadata);
        }

        return $entityManager;
    }
}
