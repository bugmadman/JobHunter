<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CoverLetter;
use App\Entity\JobPosting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CoverLetter>
 */
final class CoverLetterRepository extends ServiceEntityRepository implements CoverLetterRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CoverLetter::class);
    }

    public function findById(int $id): ?CoverLetter
    {
        return $this->find($id);
    }

    public function findLatestByJobPosting(JobPosting $posting): ?CoverLetter
    {
        return $this->findOneBy(['jobPosting' => $posting], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    public function save(CoverLetter $coverLetter): void
    {
        $this->getEntityManager()->persist($coverLetter);
        $this->getEntityManager()->flush();
    }
}
