<?php

declare(strict_types=1);

namespace App\Repository;

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

    public function save(ScanInstruction $scanInstruction): void
    {
        $this->getEntityManager()->persist($scanInstruction);
        $this->getEntityManager()->flush();
    }
}
