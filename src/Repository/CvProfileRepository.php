<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CvProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CvProfile>
 */
final class CvProfileRepository extends ServiceEntityRepository implements CvProfileRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CvProfile::class);
    }

    public function save(CvProfile $cvProfile): void
    {
        $this->getEntityManager()->persist($cvProfile);
        $this->getEntityManager()->flush();
    }
}
