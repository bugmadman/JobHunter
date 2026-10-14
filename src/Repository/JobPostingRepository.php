<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\JobPosting\JobPostingListFilter;
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

    public function findNewestFirst(JobPostingListFilter $filter): array
    {
        $query = $this->createQueryBuilder('posting')
            ->addSelect('platform')
            ->join('posting.platform', 'platform')
            ->orderBy('posting.createdAt', 'DESC')
            ->addOrderBy('posting.id', 'DESC');

        if (null !== $filter->platformId) {
            $query->andWhere('platform.id = :platform')->setParameter('platform', $filter->platformId);
        }

        if (null !== $filter->status) {
            $query->andWhere('posting.status = :status')->setParameter('status', $filter->status);
        }

        if (null !== $filter->verdict) {
            $query->andWhere('posting.verdict = :verdict')->setParameter('verdict', $filter->verdict);
        }

        /* @var list<JobPosting> */
        return $query->getQuery()->getResult();
    }

    public function save(JobPosting $posting): void
    {
        $this->getEntityManager()->persist($posting);
        $this->getEntityManager()->flush();
    }
}
