<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Platform;
use App\Entity\ScanInstruction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScanInstruction>
 */
final class ScanInstructionRepository extends ServiceEntityRepository implements ScanInstructionRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScanInstruction::class);
    }

    public function findByPlatform(Platform $platform): ?ScanInstruction
    {
        return $this->findOneBy(['platform' => $platform]);
    }

    public function findPlatformsWithoutInstruction(): array
    {
        /* @var list<Platform> */
        return $this->getEntityManager()
            ->createQuery(\sprintf(
                'SELECT p FROM %s p WHERE NOT EXISTS (SELECT s FROM %s s WHERE s.platform = p) ORDER BY p.name',
                Platform::class,
                ScanInstruction::class,
            ))
            ->getResult();
    }

    public function save(ScanInstruction $scanInstruction): void
    {
        $this->getEntityManager()->persist($scanInstruction);
        $this->getEntityManager()->flush();
    }

    public function remove(ScanInstruction $scanInstruction): void
    {
        $this->getEntityManager()->remove($scanInstruction);
        $this->getEntityManager()->flush();
    }
}
