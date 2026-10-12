<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Platform;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Platform>
 */
final class PlatformRepository extends ServiceEntityRepository implements PlatformRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Platform::class);
    }

    public function save(Platform $platform): void
    {
        $this->getEntityManager()->persist($platform);
        $this->getEntityManager()->flush();
    }
}
