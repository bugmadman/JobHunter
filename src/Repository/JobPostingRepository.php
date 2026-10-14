<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\JobPosting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JobPosting>
 */
final class JobPostingRepository extends ServiceEntityRepository implements JobPostingRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JobPosting::class);
    }

    public function findById(int $id): ?JobPosting
    {
        return $this->find($id);
    }

    public function findAllNewestFirst(): array
    {
        /* @var list<JobPosting> */
        return $this->createQueryBuilder('posting')
            ->addSelect('platform')
            ->join('posting.platform', 'platform')
            ->orderBy('posting.createdAt', 'DESC')
            ->addOrderBy('posting.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function save(JobPosting $posting): void
    {
        $this->getEntityManager()->persist($posting);
        $this->getEntityManager()->flush();
    }
}
